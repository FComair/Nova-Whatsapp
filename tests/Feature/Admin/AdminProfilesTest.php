<?php

declare(strict_types=1);

use App\Modules\Admin\Exceptions\AdminRefused;
use App\Modules\Admin\Exceptions\TeamRefused;
use App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages\CreateAdminProfile;
use App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages\EditAdminProfile;
use App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages\ListAdminProfiles;
use App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages\ViewAdminProfile;
use App\Modules\Admin\Http\Middleware\EnforceAdminIdleTimeout;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Services\AdminTeam;
use App\Modules\Admin\Services\ClientManager;
use App\Modules\Admin\Services\LegalDocumentFiles;
use App\Modules\Admin\Services\OrganizationLifecycle;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Admin\Services\SectorManager;
use App\Modules\Admin\Services\SupportTeam;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\AdminBack;
use App\Modules\Admin\Support\AdminPermissions;
use App\Modules\Admin\Support\SupportPermissions;
use App\Modules\Identity\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Administrateurs et accès (docs/38) : catalogue des rôles et permissions, invitation et activation, droits appliqués aux écrans ET aux services,
 * règles anti-verrouillage, suspension, retrait, déconnexion forcée, audit.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    ['org' => $this->org] = $this->activatedAccount();

    $this->make = function (string $role, string $email, array $attributes = []): PlatformAdmin {
        $admin = PlatformAdmin::query()->create(['email' => $email, 'password' => 'mot-de-passe-pour-les-tests-2026!']);
        $admin->forceFill(['admin_role' => $role, 'name' => ucfirst($role).' Test', 'status' => 'active', ...$attributes])->save();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        return $admin->refresh();
    };
    $this->boss = ($this->make)('super_admin', 'boss@nova.test');
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
    $this->team = fn (): AdminTeam => app(AdminTeam::class);
    $this->as = fn (PlatformAdmin $admin) => $this->flushSession()->actingAs($admin, 'platform');
    $this->mails = fn () => app('mail.manager')->mailer()->getSymfonyTransport()->messages()->map(function ($sent): array {
        $body = (string) $sent->getOriginalMessage()->getHtmlBody();
        preg_match('#/equipe/invitation/([0-9a-f-]{36})/([A-Za-z0-9]{48})#', $body, $match);

        return ['to' => $sent->getOriginalMessage()->getTo()[0]->getAddress(), 'subject' => (string) $sent->getOriginalMessage()->getSubject(), 'admin' => $match[1] ?? null, 'token' => $match[2] ?? null];
    })->all();
    $this->invite = fn (array $override = []) => ($this->team)()->create($this->boss, [...['name' => 'Camille Dupont', 'email' => 'camille@nova.test', 'role' => 'operations'], ...$override]);
});

describe('catalogue des rôles et permissions', function (): void {
    it('donne tout au Super Admin, y compris la gestion des administrateurs, et jamais celle-ci à un autre rôle', function (): void {
        expect(AdminPermissions::forRole('super_admin'))->toContain('admins.create', 'clients.delete', 'legal.download_archived');

        foreach (['operations', 'support', 'read_only', 'custom'] as $role) {
            expect(array_filter(AdminPermissions::forRole($role, ['admins.create', 'clients.view']), fn (string $permission): bool => str_starts_with($permission, 'admins.')))->toBe([]);
        }
    });

    it('définit chaque rôle prédéfini', function (): void {
        expect(AdminPermissions::forRole('operations'))->toContain('clients.suspend', 'subscriptions.edit', 'settings.edit', 'reports.export')->not->toContain('tickets.view', 'clients.delete')
            ->and(AdminPermissions::forRole('support'))->toContain('tickets.reply', 'support_agents.create', 'faq.edit', 'ratings.view')->not->toContain('clients.view', 'settings.edit', 'tickets.delete')
            ->and(AdminPermissions::forRole('read_only'))->each->toEndWith('.view');
    });

    it('filtre un rôle personnalisé sur le catalogue accordable', function (): void {
        expect(AdminPermissions::forRole('custom', ['audit.view', 'nimportequoi', 'admins.delete', 'audit.view']))->toBe(['audit.view'])
            ->and(AdminPermissions::normalise(['clients.view' => true, 'clients.edit' => false]))->toBe(['clients.view']);
    });

    it('applique les droits d\'un compte : inactif = rien, agent = tickets, éditeur = FAQ', function (): void {
        $agent = ($this->make)('super_admin', 'agent@nova.test', ['admin_role' => null, 'support_role' => 'agent']);
        $editor = ($this->make)('super_admin', 'editor@nova.test', ['admin_role' => null, 'support_role' => 'editor']);
        $suspended = ($this->make)('super_admin', 'suspended@nova.test', ['status' => 'suspended']);

        expect(AdminAccess::can('clients.edit', $this->boss))->toBeTrue()
            ->and(AdminAccess::can('clients.edit', $suspended))->toBeFalse()
            ->and(AdminAccess::can('tickets.reply', $agent))->toBeTrue()->and(AdminAccess::can('clients.view', $agent))->toBeFalse()->and(AdminAccess::can('faq.edit', $agent))->toBeFalse()
            ->and(AdminAccess::can('faq.edit', $editor))->toBeTrue()->and(AdminAccess::can('tickets.view', $editor))->toBeFalse()
            ->and(fn () => AdminAccess::authorize(($this->make)('read_only', 'ro@nova.test'), 'clients.edit'))->toThrow(AuthorizationException::class);
    });

    it('traite un compte historique sans rôle comme Super Admin', function (): void {
        $legacy = PlatformAdmin::query()->create(['email' => 'legacy@nova.test', 'password' => 'mot-de-passe-pour-les-tests-2026!']);

        expect($legacy->isSuperAdmin())->toBeTrue()->and(AdminAccess::can('admins.view', $legacy))->toBeTrue();
    });

    it('garde les droits fins des tickets : agent selon ses réglages, administrateur selon son rôle', function (): void {
        $agent = ($this->make)('super_admin', 'agent@nova.test', ['admin_role' => null, 'support_role' => 'agent']);
        $support = ($this->make)('support', 'support@nova.test');
        $custom = ($this->make)('custom', 'custom@nova.test', ['permissions' => ['tickets.view', 'tickets.delete']]);

        expect(SupportPermissions::can($agent, SupportPermissions::DELETE_TICKETS))->toBeFalse()
            ->and(SupportPermissions::can($this->boss, SupportPermissions::DELETE_TICKETS))->toBeTrue()
            ->and(SupportPermissions::can($support, SupportPermissions::DELETE_TICKETS))->toBeFalse()->and(SupportPermissions::can($support, SupportPermissions::ASSIGN_OTHERS))->toBeTrue()
            ->and(SupportPermissions::can($custom, SupportPermissions::DELETE_TICKETS))->toBeTrue()->and(SupportPermissions::can($custom, SupportPermissions::CHANGE_PRIORITY))->toBeFalse();
    });
});

describe('création et invitation', function (): void {
    it('crée l\'administrateur « invité », trace la création et envoie l\'invitation', function (): void {
        $admin = ($this->invite)();

        expect($admin)->status->toBe('invited')->admin_role->toBe('operations')->created_by->toBe($this->boss->id)->name->toBe('Camille Dupont')->permissions->toBeNull()
            ->and($admin->invitation_expires_at->isFuture())->toBeTrue();
        $mail = ($this->mails)()[0];
        expect($mail['to'])->toBe('camille@nova.test')->and($mail['token'])->toHaveLength(48)
            ->and(hash('sha256', $mail['token']))->toBe($admin->invitation_token);
        $created = ($this->audit)('admin.created')->sole();
        expect($created->actor_id)->toBe($this->boss->id)->and(json_decode($created->diff, true))->toMatchArray(['email' => 'camille@nova.test', 'role' => 'operations', 'status' => 'invited'])
            ->and(($this->audit)('admin.invited'))->toHaveCount(1);
    });

    it('enregistre la liste choisie pour un rôle personnalisé et ignore ce qui est hors catalogue', function (): void {
        $admin = ($this->invite)(['role' => 'custom', 'permissions' => ['clients.view', 'audit.view', 'admins.create', 'xxx']]);

        expect($admin->permissions)->toBe(['clients.view', 'audit.view'])->and(AdminPermissions::forRole('custom', $admin->permissions))->toBe(['clients.view', 'audit.view']);
    });

    it('refuse les données invalides', function (array $override, string $reason): void {
        expect(fn () => ($this->invite)($override))->toThrow(AdminRefused::class, $reason);
        expect(PlatformAdmin::query()->where('email', 'camille@nova.test')->exists())->toBeFalse();
    })->with([
        'nom vide' => [['name' => '  '], 'name_required'],
        'e-mail invalide' => [['email' => 'pas-un-email'], 'email_invalid'],
        'rôle inconnu' => [['role' => 'dieu'], 'role_invalid'],
        'personnalisé sans permission' => [['role' => 'custom', 'permissions' => []], 'permissions_required'],
        'personnalisé avec uniquement des permissions inconnues' => [['role' => 'custom', 'permissions' => ['admins.delete']], 'permissions_required'],
    ]);

    it('refuse un e-mail déjà utilisé, sans tenir compte de la casse', function (): void {
        ($this->invite)();

        expect(fn () => ($this->invite)(['email' => 'CAMILLE@nova.test']))->toThrow(AdminRefused::class, 'email_taken');
    });

    it('permet à l\'invité de choisir son mot de passe une seule fois, puis de devenir actif', function (): void {
        $admin = ($this->invite)();
        $mail = ($this->mails)()[0];

        $this->get("/equipe/invitation/{$mail['admin']}/{$mail['token']}")->assertOk();
        $this->post("/equipe/invitation/{$mail['admin']}/{$mail['token']}", ['password' => 'Un-mot-de-passe-solide-2026!', 'password_confirmation' => 'Un-mot-de-passe-solide-2026!'])->assertRedirect();

        expect($admin->refresh())->status->toBe('active')->invitation_token->toBeNull()
            ->and(($this->audit)('admin.activated')->sole()->actor_id)->toBe($admin->id);
        $this->post("/equipe/invitation/{$mail['admin']}/{$mail['token']}", ['password' => 'Un-autre-mot-de-passe-2026!', 'password_confirmation' => 'Un-autre-mot-de-passe-2026!'])->assertOk();
        expect(($this->audit)('admin.activated'))->toHaveCount(1);
    });

    it('renvoie l\'invitation : l\'ancien lien cesse de fonctionner', function (): void {
        ($this->invite)();
        $first = ($this->mails)()[0];

        ($this->team)()->resendInvitation($this->boss, PlatformAdmin::query()->where('email', 'camille@nova.test')->firstOrFail());
        $second = ($this->mails)()[1];

        expect($second['token'])->not->toBe($first['token'])->and(($this->audit)('admin.invitation_resent'))->toHaveCount(1);
        $this->get("/equipe/invitation/{$first['admin']}/{$first['token']}")->assertOk()->assertDontSee('name="password"', false);
        $this->get("/equipe/invitation/{$second['admin']}/{$second['token']}")->assertOk()->assertSee('name="password"', false);
    });

    it('n\'est possible que pour un Super Admin : un autre rôle est refusé', function (string $role): void {
        $other = ($this->make)($role, "{$role}@nova.test", ['permissions' => $role === 'custom' ? ['clients.view', 'clients.edit'] : null]);

        expect(fn () => ($this->team)()->create($other, ['name' => 'X Y', 'email' => 'x@nova.test', 'role' => 'read_only']))->toThrow(AdminRefused::class, 'forbidden')
            ->and(fn () => ($this->team)()->suspend($other, $this->boss))->toThrow(AdminRefused::class, 'forbidden')
            ->and(fn () => ($this->team)()->update($other, $this->boss, ['name' => 'Piraté']))->toThrow(AdminRefused::class, 'forbidden');
        expect($this->boss->refresh()->name)->not->toBe('Piraté');
    })->with(['operations', 'support', 'read_only', 'custom']);
});

describe('modification, rôles et sécurité', function (): void {
    it('modifie le profil, change le rôle et trace l\'ancien et le nouveau, avec un événement dédié aux accès', function (): void {
        $admin = ($this->make)('operations', 'ops@nova.test');

        $changes = ($this->team)()->update($this->boss, $admin, ['name' => 'Nouveau Nom', 'role' => 'custom', 'permissions' => ['faq.view']]);

        expect(array_keys($changes))->toBe(['name', 'role', 'permissions'])
            ->and($admin->refresh())->name->toBe('Nouveau Nom')->admin_role->toBe('custom')->permissions->toBe(['faq.view']);
        $updated = ($this->audit)('admin.updated')->sole();
        expect(json_decode($updated->diff, true)['role'])->toBe(['operations', 'custom'])->and(($this->audit)('admin.access_changed'))->toHaveCount(1);
    });

    it('ne fait rien et ne trace rien quand rien ne change', function (): void {
        $admin = ($this->make)('read_only', 'ro@nova.test');

        expect(($this->team)()->update($this->boss, $admin, ['name' => $admin->name, 'role' => 'read_only']))->toBe([])->and(($this->audit)('admin.updated'))->toHaveCount(0);
    });

    it('ne laisse pas un Super Admin se retirer son propre accès ni se suspendre, se supprimer ou se déconnecter', function (): void {
        $second = ($this->make)('super_admin', 'second@nova.test');
        $team = ($this->team)();

        expect(fn () => $team->update($this->boss, $this->boss, ['role' => 'read_only']))->toThrow(AdminRefused::class, 'self_access')
            ->and(fn () => $team->suspend($this->boss, $this->boss))->toThrow(AdminRefused::class, 'self_access')
            ->and(fn () => $team->remove($this->boss, $this->boss))->toThrow(AdminRefused::class, 'self_access')
            ->and(fn () => $team->forceLogout($this->boss, $this->boss))->toThrow(AdminRefused::class, 'self_action')
            ->and(fn () => $team->sendPasswordReset($this->boss, $this->boss))->toThrow(AdminRefused::class, 'self_action');
        expect($this->boss->refresh()->adminRole())->toBe('super_admin')->and($second->refresh()->isActive())->toBeTrue();

        // Changer son propre nom reste permis.
        expect(array_keys($team->update($this->boss, $this->boss, ['name' => 'Nouveau Boss'])))->toBe(['name']);
    });

    it('ne retire jamais le dernier Super Admin actif, mais le permet quand un autre reste', function (): void {
        $second = ($this->make)('super_admin', 'second@nova.test');
        $team = ($this->team)();

        $team->update($this->boss, $second, ['role' => 'read_only']);
        expect($second->refresh()->adminRole())->toBe('read_only');

        $other = ($this->make)('super_admin', 'other@nova.test');
        // Le seul autre Super Admin est suspendu : plus personne ne pourrait gérer la plateforme sans « other ».
        $team->suspend($this->boss, $other);
        expect(fn () => $team->update($other, $this->boss, ['role' => 'read_only']))->toThrow(AdminRefused::class);
        $this->boss->refresh();
        expect($this->boss->adminRole())->toBe('super_admin');
    });

    it('refuse de suspendre ou retirer le dernier Super Admin actif depuis un autre compte', function (): void {
        $last = ($this->make)('super_admin', 'last@nova.test');
        $this->boss->forceFill(['status' => 'suspended'])->save();
        $helper = ($this->make)('super_admin', 'helper@nova.test');
        $helper->forceFill(['status' => 'suspended'])->save();
        $actor = ($this->make)('super_admin', 'actor@nova.test');

        // « last » et « actor » sont actifs : suspendre l'un laisse l'autre.
        ($this->team)()->suspend($actor, $last);
        expect($last->refresh()->status)->toBe('suspended');
        expect(fn () => ($this->team)()->suspend($actor, $last))->toThrow(AdminRefused::class);
    });

    it('ne touche jamais un agent de support ni un éditeur de contenu', function (): void {
        $agent = ($this->make)('super_admin', 'agent@nova.test', ['admin_role' => null, 'support_role' => 'agent']);

        expect(fn () => ($this->team)()->update($this->boss, $agent, ['name' => 'X']))->toThrow(AdminRefused::class, 'not_admin')
            ->and(fn () => ($this->team)()->suspend($this->boss, $agent))->toThrow(AdminRefused::class, 'not_admin');
    });

    it('refuse de modifier un compte supprimé', function (): void {
        $admin = ($this->make)('read_only', 'gone@nova.test');
        ($this->team)()->remove($this->boss, $admin);

        expect(fn () => ($this->team)()->update($this->boss, $admin->refresh(), ['name' => 'X']))->toThrow(AdminRefused::class, 'removed');
    });
});

describe('suspension, réactivation, retrait et sessions', function (): void {
    it('suspend : plus d\'accès, sessions révoquées, motif et ancien statut tracés', function (): void {
        $admin = ($this->make)('operations', 'ops@nova.test');

        ($this->team)()->suspend($this->boss, $admin, 'Départ en congé');

        expect($admin->refresh())->status->toBe('suspended')->and($admin->sessions_revoked_at)->not->toBeNull()->and($admin->suspended_at)->not->toBeNull();
        $row = ($this->audit)('admin.suspended')->sole();
        expect($row->reason)->toBe('Départ en congé')->and(json_decode($row->diff, true)['status'])->toBe(['active', 'suspended']);
        ($this->as)($admin)->get('/admin/organizations')->assertForbidden();
    });

    it('refuse de suspendre un compte qui n\'est pas actif', function (): void {
        $admin = ($this->make)('operations', 'ops@nova.test', ['status' => 'invited']);

        expect(fn () => ($this->team)()->suspend($this->boss, $admin))->toThrow(AdminRefused::class, 'not_active');
    });

    it('réactive un compte suspendu : il retrouve son rôle', function (): void {
        $admin = ($this->make)('operations', 'ops@nova.test', ['last_login_at' => now()->subDay()]);
        ($this->team)()->suspend($this->boss, $admin);

        ($this->team)()->reactivate($this->boss, $admin->refresh());

        expect($admin->refresh())->status->toBe('active')->suspended_at->toBeNull();
        $this->flushSession()->withSession([EnforceAdminIdleTimeout::STARTED_KEY => now()->addSecond()->timestamp])->actingAs($admin, 'platform')->get('/admin/organizations')->assertOk();
        expect(($this->audit)('admin.reactivated')->sole()->diff)->toContain('suspended');
    });

    it('réactive en « invité » avec une nouvelle invitation un compte qui n\'a jamais choisi de mot de passe', function (): void {
        $admin = ($this->invite)();
        ($this->team)()->remove($this->boss, $admin);

        ($this->team)()->reactivate($this->boss, $admin->refresh());

        expect($admin->refresh()->status)->toBe('invited')->and(($this->mails)())->toHaveCount(2);
    });

    it('supprime en douceur : désactivé, masqué de la liste, sessions révoquées, historique gardé', function (): void {
        $admin = ($this->make)('support', 'sup@nova.test');
        DB::connection('pgsql_platform')->table('audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_type' => 'platform_admin', 'actor_id' => $admin->id, 'action' => 'client.viewed', 'created_at' => now()]);

        ($this->team)()->remove($this->boss, $admin, 'Quitte l\'équipe');

        expect($admin->refresh())->status->toBe('removed')->and($admin->removed_at)->not->toBeNull()->and($admin->sessions_revoked_at)->not->toBeNull()
            ->and(PlatformAdmin::query()->whereKey($admin->id)->exists())->toBeTrue()
            ->and(DB::connection('pgsql_platform')->table('audit_logs')->where('actor_id', $admin->id)->count())->toBe(1);
        ($this->as)($admin)->get('/admin/organizations')->assertForbidden();
        Livewire::actingAs($this->boss, 'platform')->test(ListAdminProfiles::class)->assertDontSee('sup@nova.test');
    });

    it('déconnecte partout : une session ouverte avant est refusée, une session ouverte après est acceptée', function (): void {
        $admin = ($this->make)('operations', 'ops@nova.test');
        $before = now()->subMinutes(10)->timestamp;

        ($this->team)()->forceLogout($this->boss, $admin);
        $admin->refresh();

        $this->withSession([EnforceAdminIdleTimeout::STARTED_KEY => $before])->actingAs($admin, 'platform')->get('/admin/organizations')->assertRedirect(route('filament.admin.auth.login'));
        expect(($this->audit)('admin.sessions_revoked'))->toHaveCount(1)->and(($this->audit)('platform.session_revoked')->sole()->actor_id)->toBe($admin->id);

        $this->flushSession()->withSession([EnforceAdminIdleTimeout::STARTED_KEY => now()->addSecond()->timestamp])->actingAs($admin, 'platform')->get('/admin/organizations')->assertOk();
    });

    it('ferme aussi une session ouverte avant la mise en service de la fonction (sans heure d\'ouverture)', function (): void {
        $admin = ($this->make)('operations', 'ops@nova.test');
        ($this->team)()->forceLogout($this->boss, $admin);

        $this->actingAs($admin->refresh(), 'platform')->get('/admin/organizations')->assertRedirect(route('filament.admin.auth.login'));
    });

    it('envoie un lien de réinitialisation à un compte actif seulement, et le nouveau mot de passe est enregistré', function (): void {
        $admin = ($this->make)('operations', 'ops@nova.test');
        $invited = ($this->make)('operations', 'invited@nova.test', ['status' => 'invited']);

        expect(fn () => ($this->team)()->sendPasswordReset($this->boss, $invited))->toThrow(AdminRefused::class, 'not_active');
        ($this->team)()->sendPasswordReset($this->boss, $admin);
        $mail = ($this->mails)()[0];
        app(SupportTeam::class)->accept($mail['admin'], $mail['token'], 'Nouveau-mot-de-passe-2026!');

        expect(Hash::check('Nouveau-mot-de-passe-2026!', $admin->refresh()->password))->toBeTrue()->and($admin->status)->toBe('active')
            ->and(($this->audit)('admin.password_reset_sent'))->toHaveCount(1)->and(($this->audit)('admin.password_changed'))->toHaveCount(1);
    });

    it('enregistre la dernière connexion et son adresse', function (): void {
        $admin = ($this->make)('operations', 'ops@nova.test');

        event(new Login('platform', $admin, false));

        expect($admin->refresh()->last_login_at)->not->toBeNull()->and($admin->last_login_ip)->not->toBeNull();
    });
});

describe('les permissions commandent les écrans', function (): void {
    it('montre à chaque rôle ses modules et refuse les autres', function (string $role, array $allowed, array $denied): void {
        $admin = ($this->make)($role, "{$role}@nova.test", $role === 'custom' ? ['permissions' => ['audit.view', 'settings.view']] : []);

        foreach ($allowed as $path) {
            ($this->as)($admin)->get("/admin/{$path}")->assertOk();
        }

        foreach ($denied as $path) {
            ($this->as)($admin)->get("/admin/{$path}")->assertForbidden();
        }
    })->with([
        'opérations' => ['operations', ['organizations', 'platform-settings', 'sectors', 'client-reports', 'invoices'], ['support-tickets', 'support-team', 'faq', 'audit-logs', 'admins', 'feedback']],
        'support' => ['support', ['support-tickets', 'support-team', 'faq', 'faq-categories', 'feedback', 'support-reports'], ['organizations', 'platform-settings', 'audit-logs', 'admins', 'invoices', 'legal-versions']],
        'lecture seule' => ['read_only', ['organizations', 'support-tickets', 'support-team', 'faq', 'feedback', 'platform-settings', 'audit-logs', 'invoices', 'client-reports', 'legal-versions'], ['admins']],
        'personnalisé' => ['custom', ['audit-logs', 'platform-settings'], ['organizations', 'support-tickets', 'faq', 'admins']],
    ]);

    it('réserve la gestion des administrateurs au Super Admin', function (): void {
        ($this->as)($this->boss)->get('/admin/admins')->assertOk();
        ($this->as)($this->boss)->get('/admin/admins/create')->assertOk();

        foreach (['operations', 'support', 'read_only', 'custom'] as $role) {
            $admin = ($this->make)($role, "{$role}@nova.test", ['permissions' => ['clients.view']]);
            ($this->as)($admin)->get('/admin/admins')->assertForbidden();
            ($this->as)($admin)->get('/admin/admins/create')->assertForbidden();
            ($this->as)($admin)->get("/admin/admins/{$this->boss->id}")->assertForbidden();
            ($this->as)($admin)->get("/admin/admins/{$this->boss->id}/edit")->assertForbidden();
        }
    });

    it('refuse aux agents de support les écrans des administrateurs', function (): void {
        $agent = ($this->make)('super_admin', 'agent@nova.test', ['admin_role' => null, 'support_role' => 'agent']);

        ($this->as)($agent)->get('/admin/admins')->assertForbidden();
        ($this->as)($agent)->get('/admin/support-tickets')->assertOk();
        ($this->as)($agent)->get('/admin/organizations')->assertForbidden();
    });

    it('refuse aux clients et aux visiteurs', function (): void {
        $this->get('/admin/admins')->assertRedirect('/admin/login');
        $this->flushSession()->actingAs(User::factory()->make(), 'web')->get('/admin/admins')->assertRedirect('/admin/login');
    });
});

describe('les permissions commandent aussi les services', function (): void {
    it('refuse une écriture à un administrateur en lecture seule, même sans passer par l\'écran', function (): void {
        $readOnly = ($this->make)('read_only', 'ro@nova.test');
        $org = DB::connection('pgsql_platform')->table('organizations')->first();
        $organization = PlatformOrganization::query()->findOrFail($org->id);

        expect(fn () => app(ClientManager::class)->addTag($readOnly, $organization, 'vip'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ClientManager::class)->addNote($readOnly, $organization, 'note'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(ClientManager::class)->extendTrial($readOnly, $organization, 5, null))->toThrow(AuthorizationException::class)
            ->and(fn () => app(OrganizationLifecycle::class)->suspend($organization, $readOnly->id, null))->toThrow(AuthorizationException::class)
            ->and(fn () => app(PlatformSettings::class)->update(['trial_days' => 20], $readOnly->id))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SectorManager::class)->create(['name' => 'X', 'code' => 'x'], $readOnly->id))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SupportTeam::class)->create($readOnly, ['name' => 'A B', 'email' => 'a@nova.test']))->toThrow(TeamRefused::class);
        expect(($this->audit)('client.tag_added'))->toHaveCount(0);
    });

    it('autorise exactement ce que le rôle accorde', function (): void {
        $ops = ($this->make)('operations', 'ops@nova.test');
        $organization = PlatformOrganization::query()->firstOrFail();

        app(ClientManager::class)->addTag($ops, $organization, 'vip');
        expect(($this->audit)('client.tag_added'))->toHaveCount(1)
            ->and(fn () => app(OrganizationLifecycle::class)->scheduleDeletion($organization, $ops->id, null))->toThrow(AuthorizationException::class);
    });

    it('gère le téléchargement d\'un PDF juridique archivé par sa propre permission', function (): void {
        $files = app(LegalDocumentFiles::class);
        $custom = ($this->make)('custom', 'legal@nova.test', ['permissions' => ['legal.view']]);
        $withArchive = ($this->make)('custom', 'archive@nova.test', ['permissions' => ['legal.view', 'legal.download_archived']]);
        Storage::fake('local');
        $old = $files->replace($this->boss->id, 'terms', UploadedFile::fake()->createWithContent('cgu.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"));
        $new = $files->replace($this->boss->id, 'terms', UploadedFile::fake()->createWithContent('cgu2.pdf', "%PDF-1.4\n2 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF"));

        $files->download($custom->id, 'terms', $new->id);
        expect(fn () => $files->download($custom->id, 'terms', $old->id))->toThrow(AuthorizationException::class)
            ->and($files->download($withArchive->id, 'terms', $old->id))->not->toBeNull()
            ->and(fn () => $files->replace($custom->id, 'terms', UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n%%EOF")))->toThrow(AuthorizationException::class);

        // Nettoyage des versions ajoutées (publiées donc figées) pour les autres tests.
        DB::connection('pgsql_owner')->unprepared("ALTER TABLE legal_document_versions DISABLE TRIGGER legal_versions_freeze; DELETE FROM legal_document_versions WHERE version <> '2026-10-01'; ALTER TABLE legal_document_versions ENABLE TRIGGER legal_versions_freeze");
    });
});

describe('écrans de gestion', function (): void {
    it('affiche la liste avec le rôle, le statut, la dernière connexion, qui l\'a ajouté et le nombre de permissions', function (): void {
        $ops = ($this->make)('operations', 'ops@nova.test', ['created_by' => $this->boss->id, 'last_login_at' => now()->subDays(2)]);
        ($this->make)('custom', 'cust@nova.test', ['permissions' => ['faq.view', 'faq.edit', 'audit.view'], 'status' => 'invited']);

        Livewire::actingAs($this->boss, 'platform')->test(ListAdminProfiles::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$ops])->assertSee(__('admins.roles.operations.name'))->assertSee(__('admins.statuses.invited'))
            ->assertTableColumnStateSet('permissions_count', count(AdminPermissions::forRole('operations')), $ops)
            ->assertTableColumnStateSet('creator_name', 'Super_admin Test', $ops);
    });

    it('cherche par nom ou e-mail et filtre par rôle et par statut, en masquant les comptes supprimés par défaut', function (): void {
        $ops = ($this->make)('operations', 'ops@nova.test');
        $gone = ($this->make)('support', 'gone@nova.test', ['status' => 'removed']);
        $sup = ($this->make)('support', 'sup@nova.test', ['status' => 'suspended']);

        Livewire::actingAs($this->boss, 'platform')->test(ListAdminProfiles::class)
            ->assertCanSeeTableRecords([$this->boss, $ops, $sup])->assertCanNotSeeTableRecords([$gone])
            ->searchTable('ops@')->assertCanSeeTableRecords([$ops])->assertCanNotSeeTableRecords([$sup, $this->boss])
            ->searchTable('')->filterTable('role', 'support')->assertCanSeeTableRecords([$sup])->assertCanNotSeeTableRecords([$ops, $this->boss])
            ->filterTable('role', 'super_admin')->assertCanSeeTableRecords([$this->boss])->assertCanNotSeeTableRecords([$ops, $sup])
            ->resetTableFilters()->filterTable('status', 'removed')->assertCanSeeTableRecords([$gone])->assertCanNotSeeTableRecords([$ops])
            ->resetTableFilters()->filterTable('status', 'suspended')->assertCanSeeTableRecords([$sup])->assertCanNotSeeTableRecords([$ops, $gone]);
    });

    it('filtre par date d\'ajout et par dernière connexion et trie', function (): void {
        $old = ($this->make)('operations', 'old@nova.test', ['last_login_at' => now()->subMonths(3), 'created_at' => now()->subMonths(6)]);
        $recent = ($this->make)('operations', 'recent@nova.test', ['last_login_at' => now()->subDay(), 'created_at' => now()->subDay()]);

        Livewire::actingAs($this->boss, 'platform')->test(ListAdminProfiles::class)
            ->filterTable('login', ['login_from' => now()->subWeek()->toDateString(), 'login_until' => null])->assertCanSeeTableRecords([$recent])->assertCanNotSeeTableRecords([$old])
            ->resetTableFilters()->filterTable('created', ['created_from' => null, 'created_until' => now()->subMonths(2)->toDateString()])->assertCanSeeTableRecords([$old])->assertCanNotSeeTableRecords([$recent])
            ->resetTableFilters()->sortTable('email')->assertCanSeeTableRecords([$this->boss, $old, $recent], inOrder: true);
    });

    it('affiche l\'état vide quand aucun compte ne correspond', function (): void {
        Livewire::actingAs($this->boss, 'platform')->test(ListAdminProfiles::class)->searchTable('introuvable-xyz')->assertSee(__('admins.empty.heading'));
    });

    it('crée un administrateur depuis le formulaire et l\'invite', function (): void {
        Livewire::actingAs($this->boss, 'platform')->test(CreateAdminProfile::class)
            ->fillForm(['name' => 'Léa Martin', 'email' => 'lea@nova.test', 'role' => 'custom', 'perm_clients' => ['view', 'export'], 'perm_audit' => ['view']])
            ->call('create')->assertHasNoFormErrors()->assertNotified(__('admins.create.done'));

        $created = PlatformAdmin::query()->where('email', 'lea@nova.test')->firstOrFail();
        expect($created->status)->toBe('invited')->and($created->permissions)->toBe(['clients.view', 'clients.export', 'audit.view'])->and(($this->mails)())->toHaveCount(1);
    });

    it('valide le formulaire de création : e-mail, rôle, doublon, personnalisé sans permission', function (): void {
        ($this->make)('operations', 'taken@nova.test');

        Livewire::actingAs($this->boss, 'platform')->test(CreateAdminProfile::class)->fillForm(['name' => '', 'email' => 'pas-un-email', 'role' => null])->call('create')->assertHasFormErrors(['name', 'email', 'role']);
        Livewire::actingAs($this->boss, 'platform')->test(CreateAdminProfile::class)->fillForm(['name' => 'Doublon', 'email' => 'TAKEN@nova.test', 'role' => 'read_only'])->call('create')->assertNotified(__('admins.errors.title'));
        Livewire::actingAs($this->boss, 'platform')->test(CreateAdminProfile::class)->fillForm(['name' => 'Vide', 'email' => 'vide@nova.test', 'role' => 'custom'])->call('create')->assertNotified(__('admins.errors.title'));
        expect(PlatformAdmin::query()->whereIn('email', ['vide@nova.test', 'pas-un-email'])->count())->toBe(0);
    });

    it('modifie un administrateur depuis le formulaire et refuse de retirer son propre accès', function (): void {
        $ops = ($this->make)('operations', 'ops@nova.test');

        Livewire::actingAs($this->boss, 'platform')->test(EditAdminProfile::class, ['record' => $ops->id])
            ->assertFormSet(['role' => 'operations', 'perm_clients' => ['view', 'edit', 'suspend', 'export']])
            ->fillForm(['role' => 'read_only'])->call('save')->assertNotified(__('admins.saved'));
        expect($ops->refresh()->admin_role)->toBe('read_only');

        Livewire::actingAs($this->boss, 'platform')->test(EditAdminProfile::class, ['record' => $this->boss->id])
            ->fillForm(['role' => 'read_only'])->call('save')->assertNotified(__('admins.errors.title'));
        expect($this->boss->refresh()->adminRole())->toBe('super_admin');
    });

    it('affiche le profil : informations, permissions, activité et connexions récentes', function (): void {
        $ops = ($this->make)('operations', 'ops@nova.test', ['created_by' => $this->boss->id, 'last_login_at' => now(), 'last_login_ip' => '203.0.113.9']);
        DB::connection('pgsql_platform')->table('audit_logs')->insert([
            ['id' => (string) Str::uuid(), 'actor_type' => 'platform_admin', 'actor_id' => $ops->id, 'action' => 'client.viewed', 'org_id' => $this->org->id, 'ip' => null, 'created_at' => now()],
            ['id' => (string) Str::uuid(), 'actor_type' => 'platform_admin', 'actor_id' => $ops->id, 'action' => 'platform.login', 'org_id' => null, 'ip' => '203.0.113.9', 'created_at' => now()],
        ]);

        Livewire::actingAs($this->boss, 'platform')->test(ViewAdminProfile::class, ['record' => $ops->id])->assertSuccessful()
            ->assertSee('ops@nova.test')->assertSee('203.0.113.9')->assertSee(__('admins.modules.clients'))->assertSee('client.viewed')->assertSee(__('admins.login_events.ok'));
    });

    it('propose les actions selon l\'état du compte, avec leur effet expliqué', function (): void {
        $active = ($this->make)('operations', 'a@nova.test');
        $invited = ($this->make)('operations', 'i@nova.test', ['status' => 'invited']);
        $suspended = ($this->make)('operations', 's@nova.test', ['status' => 'suspended']);

        Livewire::actingAs($this->boss, 'platform')->test(ViewAdminProfile::class, ['record' => $active->id])
            ->assertActionVisible('suspend')->assertActionVisible('force_logout')->assertActionVisible('reset_password')->assertActionHidden('reactivate')->assertActionHidden('resend_invitation');
        Livewire::actingAs($this->boss, 'platform')->test(ViewAdminProfile::class, ['record' => $invited->id])->assertActionVisible('resend_invitation')->assertActionHidden('reset_password');
        Livewire::actingAs($this->boss, 'platform')->test(ViewAdminProfile::class, ['record' => $suspended->id])->assertActionVisible('reactivate')->assertActionHidden('suspend');
        Livewire::actingAs($this->boss, 'platform')->test(ViewAdminProfile::class, ['record' => $this->boss->id])->assertActionHidden('suspend')->assertActionHidden('remove')->assertActionHidden('force_logout');
    });

    it('suspend et réactive depuis la fiche, avec confirmation et message de réussite', function (): void {
        $admin = ($this->make)('operations', 'ops@nova.test');

        Livewire::actingAs($this->boss, 'platform')->test(ViewAdminProfile::class, ['record' => $admin->id])
            ->callAction('suspend', ['reason' => 'Test'])->assertNotified(__('admins.suspend.done'));
        expect($admin->refresh()->status)->toBe('suspended');

        Livewire::actingAs($this->boss, 'platform')->test(ViewAdminProfile::class, ['record' => $admin->id])->callAction('reactivate')->assertNotified(__('admins.reactivate.done'));
        expect($admin->refresh()->status)->toBe('active');
    });

    it('supprime depuis la fiche et déconnecte partout', function (): void {
        $admin = ($this->make)('support', 'sup@nova.test');

        Livewire::actingAs($this->boss, 'platform')->test(ViewAdminProfile::class, ['record' => $admin->id])->callAction('force_logout')->assertNotified(__('admins.logout.done'));
        expect($admin->refresh()->sessions_revoked_at)->not->toBeNull();

        Livewire::actingAs($this->boss, 'platform')->test(ViewAdminProfile::class, ['record' => $admin->id])->callAction('remove', ['reason' => 'Départ'])->assertNotified(__('admins.remove.done'));
        expect($admin->refresh()->status)->toBe('removed');
    });
});

describe('export et traductions', function (): void {
    it('exporte la liste filtrée en CSV, sans secret, et le trace', function (): void {
        ($this->make)('operations', 'ops@nova.test', ['name' => '=cmd|calc', 'created_by' => $this->boss->id]);

        $component = Livewire::actingAs($this->boss, 'platform')->test(ListAdminProfiles::class)->callAction('export');
        $component->assertFileDownloaded();

        $row = ($this->audit)('admin.exported')->sole();
        expect($row->actor_id)->toBe($this->boss->id)->and(json_decode($row->diff, true)['rows'])->toBe(2);
    });

    it('n\'exporte que pour qui peut voir la liste', function (): void {
        $ops = ($this->make)('operations', 'ops@nova.test');

        expect(fn () => ($this->team)()->export($ops, PlatformAdmin::query()))->toThrow(AdminRefused::class, 'forbidden');
    });

    it('a un retour dans les trois langues et ses textes complets', function (): void {
        $flat = function (array $tree, string $prefix = '') use (&$flat): array {
            $out = [];

            foreach ($tree as $key => $value) {
                is_array($value) ? $out += $flat($value, "{$prefix}{$key}.") : $out["{$prefix}{$key}"] = $value;
            }

            return $out;
        };
        $en = $flat(trans('admins', [], 'en'));

        foreach (['fr', 'nl'] as $locale) {
            $other = $flat(trans('admins', [], $locale));
            expect(array_keys($other))->toBe(array_keys($en))->and(array_filter($other, fn ($text): bool => $text === ''))->toBe([]);
        }

        foreach (AdminPermissions::all() as $permission) {
            expect(Lang::has("admins.permissions.{$permission}", 'fr'))->toBeTrue();
        }
    });

    it('mène le bouton Retour de chaque écran à son parent', function (): void {
        $back = fn (string $name, array $parameters = []) => AdminBack::for(Route::getRoutes()->getByName('filament.admin.'.$name)?->bind(request()->create(route('filament.admin.'.$name, $parameters))));

        expect($back('resources.admins.index')['label'])->toBe(__('admin.back.dashboard'))
            ->and($back('resources.admins.view', ['record' => 'abc'])['label'])->toBe(__('admin.back.admins'))
            ->and($back('resources.admins.create')['url'])->toBe(route('filament.admin.resources.admins.index'))
            ->and($back('resources.admins.edit', ['record' => 'abc']))->toMatchArray(['label' => __('admin.back.admin_profile'), 'url' => route('filament.admin.resources.admins.view', ['record' => 'abc'])]);
    });
});
