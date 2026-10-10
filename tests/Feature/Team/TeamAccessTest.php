<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Staff\Models\Invitation;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Team\Notifications\MemberAccessNotification;
use App\Modules\Team\Support\Capabilities;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Équipe et accès (docs/34) : invitation d'un praticien ou d'un assistant avec ses permissions, modification, suspension, retrait, sécurité
 * (propriétaire protégé, pas d'auto-promotion, isolation entre établissements) et application des permissions côté serveur.
 */
function inviteMails(): array
{
    return app('mail.manager')->mailer()->getSymfonyTransport()->messages()->map(function ($sent): array {
        $body = (string) $sent->getOriginalMessage()->getHtmlBody();
        preg_match('#/invitation/([A-Za-z0-9]{48})#', $body, $match);

        return ['to' => $sent->getOriginalMessage()->getTo()[0]->getAddress(), 'token' => $match[1] ?? null];
    })->all();
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['signup_plan' => 'business', 'name' => 'Salon Aurore', 'locale' => 'fr']);

    $this->db = fn () => DB::connection('pgsql_owner');
    $this->member = function (string $role, array $attributes = [], ?string $email = null): User {
        return $this->asOwner(function () use ($role, $attributes, $email): User {
            $user = User::factory()->create(['email' => $email ?? $role.'-'.uniqid().'@example.com']);
            OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role, 'status' => 'active', ...$attributes]);

            return $user;
        });
    };
    $this->as = function (User $user) {
        auth()->forgetGuards();

        return $this->signedIn($user, $this->org);
    };
    $this->row = fn (User $user) => ($this->db)()->table('org_members')->where('user_id', $user->id)->first();
    $this->audit = fn (string $action) => ($this->db)()->table('audit_logs')->where('action', $action)->get();
    $this->memberId = fn (User $user): string => (string) ($this->row)($user)->id;
    $this->payload = fn (array $overrides = []): array => ['name' => 'Élodie Martin', 'email' => 'elodie@example.com', 'role' => 'receptionist', 'capabilities' => Capabilities::defaults('receptionist'), ...$overrides];
});

describe('capacités', function (): void {
    it('reproduit par défaut la matrice des rôles (docs/02) : rien ne change sans réglage', function (): void {
        $practitioner = Capabilities::effective('practitioner');
        $assistant = Capabilities::effective('receptionist');
        $manager = Capabilities::effective('site_manager');

        expect($practitioner)->toContain('calendar.view', 'schedules.edit_own', 'reports.view')->not->toContain('settings.billing', 'settings.team', 'appointments.view_all', 'customers.view_all', 'reports.financial')
            ->and($assistant)->toContain('appointments.view_all', 'customers.view_all', 'loyalty.redeem')->not->toContain('reports.view', 'appointments.view_notes', 'settings.billing')
            ->and($manager)->toContain('services.prices', 'reports.export', 'loyalty.adjust')->not->toContain('settings.billing', 'settings.team', 'loyalty.configure', 'reports.financial')
            ->and(Capabilities::effective('site_manager', null, true))->toContain('reports.financial')
            ->and(Capabilities::effective('owner'))->toBe(Capabilities::all());
    });

    it('ne garde que les écarts avec le rôle de base et les relit à l’identique', function (): void {
        $wanted = [...Capabilities::defaults('receptionist'), 'reports.view'];
        $wanted = array_values(array_diff($wanted, ['appointments.cancel']));

        $overrides = Capabilities::overridesFor('receptionist', $wanted);

        expect($overrides)->toBe(['appointments.cancel' => false, 'reports.view' => true])
            ->and(Capabilities::effective('receptionist', $overrides))->toEqualCanonicalizing($wanted);
    });

    it('applique les implications et ignore ce qui est inconnu ou mal typé', function (): void {
        expect(Capabilities::effective('receptionist', ['reports.all_data' => true]))->toContain('reports.view')
            ->and(Capabilities::effective('practitioner', ['customers.view_all' => true]))->toContain('customers.view_own')
            ->and(Capabilities::clean(['nope' => true, 'reports.view' => 'yes', 'reports.export' => true]))->toBe(['reports.export' => true])
            ->and(Capabilities::effective('owner', ['settings.billing' => false]))->toContain('settings.billing');
    });
    it('traduit chaque capacité, chaque groupe et chaque message d\'erreur dans les trois langues', function (): void {
        foreach (['fr', 'nl', 'en'] as $locale) {
            foreach (Capabilities::all() as $capability) {
                foreach (['label', 'hint'] as $part) {
                    $key = "ui.team.caps.{$capability}.{$part}";
                    expect(__($key, [], $locale))->not->toBe($key, "{$key} manquant en {$locale}");
                }
            }

            foreach (array_keys(Capabilities::GROUPS) as $group) {
                expect(__("ui.team.groups.{$group}", [], $locale))->not->toBe("ui.team.groups.{$group}");
            }

            foreach (['forbidden', 'role_invalid', 'already_member', 'already_member_inactive', 'already_invited', 'email_unavailable', 'essential_access', 'protected_grant', 'protected_change', 'cannot_grant', 'owner_protected', 'self', 'peer_manager', 'not_active', 'not_suspended', 'already_removed', 'not_found'] as $error) {
                expect(__("ui.team.errors.{$error}", [], $locale))->not->toBe("ui.team.errors.{$error}");
            }
        }
    });
});

describe('accès à la page', function (): void {
    it('est réservé à qui peut gérer l\'équipe', function (): void {
        ($this->as)($this->owner)->get('/reglages/equipe')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Account/Team')->has('members', 1)->where('is_owner', true)->has('catalog.groups'));

        foreach (['site_manager', 'practitioner', 'receptionist'] as $role) {
            ($this->as)(($this->member)($role))->get('/reglages/equipe')->assertForbidden();
        }

        ($this->as)(($this->member)('receptionist', ['permissions' => ['settings.team' => true]]))->get('/reglages/equipe')->assertOk();
    });

    it('liste le propriétaire, les membres et les invitations avec leur statut', function (): void {
        ($this->member)('practitioner', ['name' => 'Anna Dupont']);
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)())->assertSessionHasNoErrors();

        ($this->as)($this->owner)->get('/reglages/equipe')->assertInertia(fn (Assert $page) => $page->has('members', 3)
            ->where('members.0.is_owner', true)->where('members.0.level', 'full')
            ->where('members', fn ($members) => collect($members)->pluck('status')->sort()->values()->all() === ['active', 'active', 'invited']));
    });
});

describe('invitation', function (): void {
    it('invite un assistant sans fiche praticien, avec ses permissions, et trace l\'action', function (): void {
        $capabilities = [...Capabilities::defaults('receptionist'), 'reports.view'];

        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)(['capabilities' => $capabilities, 'phone' => '+32 470 00 00 00', 'notes' => 'Remplace Julie le mardi']))
            ->assertSessionHasNoErrors()->assertSessionHas('status', __('ui.team.flash.invited'));

        $invitation = Invitation::query()->withoutGlobalScopes()->where('email', 'elodie@example.com')->first() ?? ($this->asOwner(fn () => Invitation::query()->firstOrFail()));

        expect($invitation)->role->toBe('receptionist')->practitioner_id->toBeNull()->name->toBe('Élodie Martin')->preset->toBe('custom')
            ->and($invitation->permissions)->toBe(['reports.view' => true])
            ->and($this->asOwner(fn () => Practitioner::query()->count()))->toBe(0)
            ->and(inviteMails()[0]['to'])->toBe('elodie@example.com')->and(inviteMails()[0]['token'])->toMatch('/^[A-Za-z0-9]{48}$/');

        $audit = ($this->audit)('team.invited')->sole();
        expect($audit->actor_id)->toBe($this->owner->id)->and(json_decode((string) $audit->diff, true))->toMatchArray(['email' => 'elodie@example.com', 'role' => 'receptionist']);
    });

    it('invite un praticien avec sa fiche praticien', function (): void {
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)(['role' => 'practitioner', 'capabilities' => Capabilities::defaults('practitioner')]))->assertSessionHasNoErrors();

        $practitioner = $this->asOwner(fn () => Practitioner::query()->firstOrFail());
        $invitation = $this->asOwner(fn () => Invitation::query()->firstOrFail());

        expect($practitioner->name)->toBe('Élodie Martin')->and($invitation->practitioner_id)->toBe($practitioner->id)->and($invitation->preset)->toBe('practitioner')->and($invitation->permissions)->toBeNull();
    });

    it('signale précisément une adresse déjà dans l\'équipe ou déjà invitée, et reste neutre pour un compte ailleurs', function (): void {
        $existing = ($this->member)('practitioner', [], 'deja@example.com');
        $this->asOwner(fn () => User::factory()->create(['email' => 'ailleurs@example.com']));

        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)(['email' => 'deja@example.com']))->assertSessionHasErrors(['team_code' => 'already_member']);
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)(['email' => 'ailleurs@example.com']))->assertSessionHasErrors(['team_code' => 'email_unavailable']);
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)())->assertSessionHasNoErrors();
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)())->assertSessionHasErrors(['team_code' => 'already_invited']);

        ($this->db)()->table('org_members')->where('user_id', $existing->id)->update(['status' => 'disabled']);
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)(['email' => 'deja@example.com']))->assertSessionHasErrors(['team_code' => 'already_member_inactive']);
    });

    it('valide les champs', function (): void {
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)(['name' => '', 'email' => 'pas-un-email', 'role' => 'owner']))->assertSessionHasErrors(['name', 'email', 'role']);
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)(['capabilities' => 'x']))->assertSessionHasErrors('capabilities');
    });

    it('crée le compte à l\'acceptation avec le rôle, le nom et les permissions de l\'invitation', function (): void {
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)(['capabilities' => [...Capabilities::defaults('receptionist'), 'reports.view', 'reports.all_data']]));
        $token = (string) inviteMails()[0]['token'];
        auth()->forgetGuards();

        $this->post("/invitation/{$token}", ['password' => 'Sol1de-Phrase-Pour-Test!', 'password_confirmation' => 'Sol1de-Phrase-Pour-Test!', 'accept_terms' => true, 'accept_privacy' => true])->assertSessionHasNoErrors()->assertRedirect('/tableau-de-bord');

        $member = ($this->db)()->table('org_members')->join('users', 'users.id', '=', 'org_members.user_id')->where('users.email', 'elodie@example.com')->first();
        expect($member->role)->toBe('receptionist')->and($member->name)->toBe('Élodie Martin')->and($member->status)->toBe('active')
            ->and(json_decode((string) $member->permissions, true))->toEqual(['reports.all_data' => true, 'reports.view' => true]);
    });

    it('renvoie une invitation (le lien précédent cesse de marcher) et peut l\'annuler', function (): void {
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)());
        $first = (string) inviteMails()[0]['token'];
        $id = (string) ($this->db)()->table('invitations')->value('id');

        ($this->as)($this->owner)->post("/reglages/equipe/{$id}/invitation")->assertSessionHasNoErrors();
        $mails = inviteMails();
        $second = (string) end($mails)['token'];

        expect($second)->not->toBe($first)->and(($this->db)()->table('invitations')->whereNull('revoked_at')->count())->toBe(1);

        $pending = (string) ($this->db)()->table('invitations')->whereNull('revoked_at')->value('id');
        ($this->as)($this->owner)->delete("/reglages/equipe/{$pending}/invitation")->assertSessionHasNoErrors();

        expect(($this->db)()->table('invitations')->whereNull('revoked_at')->count())->toBe(0)->and(($this->audit)('team.invitation_cancelled'))->toHaveCount(1);
        auth()->forgetGuards();
        $this->get("/invitation/{$second}")->assertOk();
        $this->post("/invitation/{$second}", ['password' => 'Sol1de-Phrase-Pour-Test!', 'password_confirmation' => 'Sol1de-Phrase-Pour-Test!', 'accept_terms' => true, 'accept_privacy' => true])->assertRedirect("/invitation/{$second}");
    });
});

describe('modification des accès', function (): void {
    it('change les permissions, trace l\'avant et l\'après et prévient la personne', function (): void {
        Notification::fake();
        $user = ($this->member)('receptionist', ['name' => 'Élodie Martin']);
        $wanted = array_values(array_diff([...Capabilities::defaults('receptionist'), 'reports.view'], ['appointments.cancel']));

        ($this->as)($this->owner)->put('/reglages/equipe/'.($this->memberId)($user), ['capabilities' => $wanted])->assertSessionHasNoErrors()->assertSessionHas('status', __('ui.team.flash.saved'));

        $row = ($this->row)($user);
        expect(json_decode((string) $row->permissions, true))->toEqual(['appointments.cancel' => false, 'reports.view' => true])->and($row->preset)->toBe('custom');

        $audit = ($this->audit)('team.access_updated')->sole();
        $diff = json_decode((string) $audit->diff, true);
        expect($audit->actor_id)->toBe($this->owner->id)->and($diff['added'])->toBe(['reports.view'])->and($diff['removed'])->toBe(['appointments.cancel'])
            ->and($diff['permissions'])->toEqual([null, ['appointments.cancel' => false, 'reports.view' => true]])->and($diff['role'])->toBe(['receptionist', 'receptionist']);
        Notification::assertSentTo($user, MemberAccessNotification::class);
    });

    it('change le rôle de base et revient aux défauts quand les capacités les égalent', function (): void {
        $user = ($this->member)('receptionist');

        ($this->as)($this->owner)->put('/reglages/equipe/'.($this->memberId)($user), ['role' => 'practitioner', 'capabilities' => Capabilities::defaults('practitioner')])->assertSessionHasNoErrors();

        $row = ($this->row)($user);
        expect($row->role)->toBe('practitioner')->and($row->permissions)->toBeNull()->and($row->preset)->toBe('practitioner');
    });

    it('refuse de retirer des accès essentiels sans confirmation explicite', function (): void {
        $user = ($this->member)('practitioner');
        $without = array_values(array_diff(Capabilities::defaults('practitioner'), ['dashboard.view']));

        ($this->as)($this->owner)->put('/reglages/equipe/'.($this->memberId)($user), ['capabilities' => $without])->assertSessionHasErrors(['team_code' => 'essential_access']);
        expect(($this->row)($user)->permissions)->toBeNull();

        ($this->as)($this->owner)->put('/reglages/equipe/'.($this->memberId)($user), ['capabilities' => $without, 'acknowledged' => true])->assertSessionHasNoErrors();
        expect(json_decode((string) ($this->row)($user)->permissions, true))->toEqual(['dashboard.view' => false]);
    });

    it('modifie une invitation en attente', function (): void {
        ($this->as)($this->owner)->post('/reglages/equipe', ($this->payload)());
        $id = (string) ($this->db)()->table('invitations')->value('id');

        ($this->as)($this->owner)->put("/reglages/equipe/{$id}", ['capabilities' => [...Capabilities::defaults('receptionist'), 'reports.export']])->assertSessionHasNoErrors();

        expect(json_decode((string) ($this->db)()->table('invitations')->value('permissions'), true))->toEqual(['reports.export' => true]);
    });
});

describe('sécurité', function (): void {
    it('protège le propriétaire et interdit de modifier son propre accès', function (): void {
        $manager = ($this->member)('receptionist', ['permissions' => ['settings.team' => true]]);
        $ownerMemberId = (string) ($this->row)($this->owner)->id;

        ($this->as)($this->owner)->put("/reglages/equipe/{$ownerMemberId}", ['capabilities' => []])->assertSessionHasErrors(['team_code' => 'owner_protected']);
        ($this->as)($this->owner)->post("/reglages/equipe/{$ownerMemberId}/suspendre")->assertSessionHasErrors(['team_code' => 'owner_protected']);
        ($this->as)($manager)->delete("/reglages/equipe/{$ownerMemberId}")->assertSessionHasErrors(['team_code' => 'owner_protected']);
        ($this->as)($manager)->put('/reglages/equipe/'.($this->memberId)($manager), ['capabilities' => Capabilities::all()])->assertSessionHasErrors(['team_code' => 'self']);

        expect(($this->row)($this->owner)->status)->toBe('active');
    });

    it('n\'accorde que ce que l\'on a soi-même, et laisse la facturation et l\'équipe au propriétaire', function (): void {
        $manager = ($this->member)('receptionist', ['permissions' => ['settings.team' => true]]);
        $target = ($this->member)('practitioner');
        $id = ($this->memberId)($target);

        // Le gestionnaire n'a ni les rapports financiers ni la facturation : il ne peut pas les donner.
        ($this->as)($manager)->put("/reglages/equipe/{$id}", ['capabilities' => [...Capabilities::defaults('practitioner'), 'reports.financial']])->assertSessionHasErrors(['team_code' => 'cannot_grant']);
        ($this->as)($manager)->put("/reglages/equipe/{$id}", ['capabilities' => [...Capabilities::defaults('practitioner'), 'settings.billing']])->assertSessionHasErrors(['team_code' => 'protected_grant']);
        ($this->as)($manager)->put("/reglages/equipe/{$id}", ['capabilities' => [...Capabilities::defaults('practitioner'), 'settings.team']])->assertSessionHasErrors(['team_code' => 'protected_grant']);
        ($this->as)($manager)->post('/reglages/equipe', ($this->payload)(['capabilities' => [...Capabilities::defaults('receptionist'), 'settings.billing']]))->assertSessionHasErrors(['team_code' => 'protected_grant']);
        expect(($this->row)($target)->permissions)->toBeNull();

        // Le propriétaire, lui, peut tout donner, y compris la facturation.
        ($this->as)($this->owner)->put("/reglages/equipe/{$id}", ['capabilities' => [...Capabilities::defaults('practitioner'), 'settings.billing']])->assertSessionHasNoErrors();
        ($this->as)(User::query()->findOrFail($target->id))->get('/facturation')->assertOk();
    });

    it('réserve au propriétaire la modification d\'une personne qui gère l\'équipe', function (): void {
        $first = ($this->member)('receptionist', ['permissions' => ['settings.team' => true]]);
        $second = ($this->member)('practitioner', ['permissions' => ['settings.team' => true]]);

        ($this->as)($first)->post('/reglages/equipe/'.($this->memberId)($second).'/suspendre')->assertSessionHasErrors(['team_code' => 'peer_manager']);
        ($this->as)($first)->put('/reglages/equipe/'.($this->memberId)($second), ['capabilities' => []])->assertSessionHasErrors(['team_code' => 'peer_manager']);
        expect(($this->row)($second)->status)->toBe('active');
    });

    it('n\'atteint jamais un autre établissement', function (): void {
        $mine = ($this->member)('practitioner');
        ['user' => $otherOwner, 'org' => $otherOrg] = $this->activatedAccount(['name' => 'Cabinet Zéphyr'], 'zephyr@example.com');
        $otherMember = $this->asOwner(function () use ($otherOrg): User {
            $user = User::factory()->create();
            OrgMember::factory()->create(['org_id' => $otherOrg->id, 'user_id' => $user->id, 'role' => 'practitioner', 'status' => 'active']);

            return $user;
        });
        $otherId = (string) ($this->row)($otherMember)->id;

        auth()->forgetGuards();
        $this->signedIn($this->owner, $this->org)->put("/reglages/equipe/{$otherId}", ['capabilities' => []])->assertSessionHasErrors(['team_code' => 'not_found']);
        $this->signedIn($this->owner, $this->org)->post("/reglages/equipe/{$otherId}/suspendre")->assertSessionHasErrors(['team_code' => 'not_found']);
        $this->signedIn($this->owner, $this->org)->get('/reglages/equipe')->assertInertia(fn (Assert $page) => $page->has('members', 2));
        expect(($this->row)($otherMember)->status)->toBe('active')->and(($this->row)($mine)->status)->toBe('active');
    });
});

describe('statuts', function (): void {
    it('suspend : la personne ne passe plus, dès sa requête suivante ; puis la réactive', function (): void {
        Notification::fake();
        $user = ($this->member)('practitioner');
        $id = ($this->memberId)($user);

        ($this->as)($user)->get('/tableau-de-bord')->assertOk();
        ($this->as)($this->owner)->post("/reglages/equipe/{$id}/suspendre", ['reason' => 'Congé long'])->assertSessionHasNoErrors();

        expect(($this->row)($user)->status)->toBe('disabled')->and(($this->row)($user)->suspended_at)->not->toBeNull();
        $audit = ($this->audit)('team.suspended')->sole();
        expect($audit->reason)->toBe('Congé long')->and(json_decode((string) $audit->diff, true)['status'])->toBe(['active', 'disabled']);
        Notification::assertSentTo($user, MemberAccessNotification::class);

        ($this->as)($user)->get('/tableau-de-bord')->assertForbidden();

        ($this->as)($this->owner)->post("/reglages/equipe/{$id}/reactiver")->assertSessionHasNoErrors();
        expect(($this->row)($user)->status)->toBe('active');
        ($this->as)($user)->get('/tableau-de-bord')->assertOk();
    });

    it('retire l\'accès en gardant l\'historique et compte les rendez-vous à venir', function (): void {
        $user = ($this->member)('practitioner');
        $practitionerId = $this->asOwner(fn () => Practitioner::query()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'name' => 'Anna', 'color' => '#3366ff', 'status' => 'active', 'position' => 1])->id);
        $id = ($this->memberId)($user);

        ($this->as)($this->owner)->get('/reglages/equipe')->assertInertia(fn (Assert $page) => $page->where('members', fn ($members) => collect($members)->firstWhere('id', $id)['upcoming_appointments'] === 0));
        ($this->as)($this->owner)->delete("/reglages/equipe/{$id}")->assertSessionHasNoErrors();

        expect(($this->row)($user)->status)->toBe('removed')->and(($this->row)($user)->removed_at)->not->toBeNull()
            ->and(($this->db)()->table('practitioners')->where('id', $practitionerId)->value('user_id'))->toBe($user->id)
            ->and(json_decode((string) ($this->audit)('team.removed')->sole()->diff, true))->toHaveKey('upcoming_appointments');
        ($this->as)($user)->get('/tableau-de-bord')->assertForbidden();

        ($this->as)($this->owner)->delete("/reglages/equipe/{$id}")->assertSessionHasErrors(['team_code' => 'already_removed']);
        ($this->as)($this->owner)->post("/reglages/equipe/{$id}/reactiver")->assertSessionHasNoErrors();
        expect(($this->row)($user)->status)->toBe('active');
    });

    it('envoie un lien de réinitialisation du mot de passe à un membre actif seulement', function (): void {
        Notification::fake();
        $user = ($this->member)('receptionist');
        $id = ($this->memberId)($user);

        ($this->as)($this->owner)->post("/reglages/equipe/{$id}/mot-de-passe")->assertSessionHasNoErrors();
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        expect(($this->audit)('team.password_reset_sent'))->toHaveCount(1);

        ($this->db)()->table('org_members')->where('id', $id)->update(['status' => 'disabled']);
        ($this->as)($this->owner)->post("/reglages/equipe/{$id}/mot-de-passe")->assertSessionHasErrors(['team_code' => 'not_active']);
    });
});

describe('permissions appliquées côté serveur', function (): void {
    it('refuse à chaque rôle ce que la matrice par défaut lui interdit', function (string $role, string $method, string $url, int $status): void {
        $user = $role === 'owner' ? $this->owner : ($this->member)($role);

        ($this->as)($user)->call($method, $url)->assertStatus($status);
    })->with([
        'propriétaire : facturation' => ['owner', 'GET', '/facturation', 200],
        'propriétaire : équipe' => ['owner', 'GET', '/reglages/equipe', 200],
        'responsable : facturation' => ['site_manager', 'GET', '/facturation', 403],
        'responsable : WhatsApp' => ['site_manager', 'GET', '/whatsapp', 403],
        'responsable : équipe' => ['site_manager', 'GET', '/reglages/equipe', 403],
        'responsable : programme de fidélité' => ['site_manager', 'GET', '/clients/fidelite/programme', 403],
        'responsable : rapports' => ['site_manager', 'GET', '/rapports', 200],
        'praticien : fidélité' => ['practitioner', 'GET', '/clients/fidelite', 403],
        'praticien : export des rapports' => ['practitioner', 'GET', '/rapports/export/apercu', 403],
        'praticien : créer une prestation' => ['practitioner', 'POST', '/configuration/services', 403],
        'praticien : gérer les praticiens' => ['practitioner', 'POST', '/configuration/praticiens', 403],
        'praticien : QR WhatsApp' => ['practitioner', 'GET', '/qr-whatsapp', 403],
        'praticien : clients' => ['practitioner', 'GET', '/clients', 200],
        'praticien : calendriers' => ['practitioner', 'GET', '/calendriers', 200],
        'assistant : rapports' => ['receptionist', 'GET', '/rapports', 403],
        'assistant : calendriers' => ['receptionist', 'GET', '/calendriers', 403],
        'assistant : fidélité' => ['receptionist', 'GET', '/clients/fidelite', 200],
        'assistant : planning' => ['receptionist', 'GET', '/planning', 200],
        'assistant : clients' => ['receptionist', 'GET', '/clients', 200],
        'assistant : profil de l\'entreprise' => ['receptionist', 'PUT', '/reglages/profil/entreprise', 403],
    ]);

    it('applique les écarts de permissions : donner et retirer un accès change ce qui est permis', function (): void {
        $assistant = ($this->member)('receptionist', ['permissions' => ['reports.view' => true, 'reports.all_data' => true, 'calendar.view' => false]]);
        $practitioner = ($this->member)('practitioner', ['permissions' => ['whatsapp.templates' => true, 'settings.business' => true]]);

        ($this->as)($assistant)->get('/rapports')->assertOk();
        ($this->as)($assistant)->get('/planning')->assertForbidden();
        ($this->as)($practitioner)->get('/whatsapp')->assertOk();
        ($this->as)($practitioner)->post('/whatsapp')->assertForbidden();
        ($this->as)($practitioner)->post('/whatsapp/modeles/synchroniser')->assertStatus(302);
        ($this->as)($practitioner)->get('/qr-whatsapp')->assertForbidden();
        ($this->as)($practitioner)->put('/reglages/profil/entreprise', [])->assertSessionHasErrors();
    });

    it('sépare « terminé » et « absent », et le prix des autres champs d\'une prestation', function (): void {
        $location = ($this->db)()->table('locations')->where('org_id', $this->org->id)->value('id');
        $customer = (string) Str::uuid();
        ($this->db)()->table('customers')->insert(['id' => $customer, 'org_id' => $this->org->id, 'phone_e164' => '+32470000001', 'reference_number' => 1]);
        $practitioner = (string) Str::uuid();
        ($this->db)()->table('practitioners')->insert(['id' => $practitioner, 'org_id' => $this->org->id, 'name' => 'Agnès']);
        $service = (string) Str::uuid();
        ($this->db)()->table('services')->insert(['id' => $service, 'org_id' => $this->org->id, 'name' => 'Coupe', 'duration_minutes' => 30, 'price_cents' => 4000, 'vat_rate' => 21]);
        $booking = (string) Str::uuid();
        $start = now()->subHours(3);
        ($this->db)()->table('bookings')->insert([
            'id' => $booking, 'org_id' => $this->org->id, 'location_id' => $location, 'customer_id' => $customer, 'practitioner_id' => $practitioner, 'service_id' => $service, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30),
            'blocked_from' => $start, 'blocked_until' => $start->copy()->addMinutes(30), 'status' => 'confirmed', 'source' => 'dashboard', 'price_cents' => 4000, 'vat_rate' => 21, 'currency' => 'EUR', 'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $noComplete = ($this->member)('receptionist', ['permissions' => ['appointments.complete' => false]]);
        $none = ($this->member)('receptionist', ['permissions' => ['appointments.complete' => false, 'appointments.no_show' => false]]);

        ($this->as)($noComplete)->post("/planning/rendez-vous/{$booking}/resultat", ['outcome' => 'completed'])->assertForbidden();
        ($this->as)($none)->post("/planning/rendez-vous/{$booking}/resultat", ['outcome' => 'no_show'])->assertForbidden();
        expect(($this->db)()->table('bookings')->where('id', $booking)->value('status'))->toBe('confirmed');
        ($this->as)($noComplete)->post("/planning/rendez-vous/{$booking}/resultat", ['outcome' => 'no_show'])->assertRedirect();
        expect(($this->db)()->table('bookings')->where('id', $booking)->value('status'))->toBe('no_show');

        // Prestation : « modifier » ne suffit pas pour changer le prix ou la TVA.
        $editor = ($this->member)('receptionist', ['permissions' => ['services.edit' => true]]);
        $payload = ['name' => 'Coupe longue', 'duration_minutes' => 30, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'price' => '40,00', 'vat_rate' => 21, 'capacity' => 1, 'bookable_online' => true, 'notes_required' => false];

        ($this->as)($editor)->put("/configuration/services/{$service}", [...$payload, 'price' => '55,00'])->assertForbidden();
        ($this->as)($editor)->put("/configuration/services/{$service}", [...$payload, 'vat_rate' => 6])->assertForbidden();
        ($this->as)($editor)->put("/configuration/services/{$service}", $payload)->assertSessionHasNoErrors();
        expect(($this->db)()->table('services')->where('id', $service)->value('name'))->toBe('Coupe longue')->and(($this->db)()->table('services')->where('id', $service)->value('price_cents'))->toBe(4000);
    });

    it('applique les permissions du propriétaire à l\'API de la page (jamais l\'interface seule)', function (): void {
        $user = ($this->member)('practitioner');

        ($this->as)($user)->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('auth.organization.role', 'practitioner')
            ->where('auth.organization.permissions', fn ($permissions) => in_array('calendar.view', $permissions->all(), true) && ! in_array('settings.billing', $permissions->all(), true)));
    });
});
