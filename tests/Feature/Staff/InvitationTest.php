<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Service;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Invitation;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Invitation d'un praticien par lien (flowcharts 1C et 5B/E13) : lien valable 3 jours, nouvelle invitation
 * qui invalide l'ancienne, acceptation avec création du compte.
 */

/**
 * Derniers e-mails envoyés par l'application (transport « array » des tests).
 *
 * @return list<array{to: string, subject: string, body: string, token: string|null}>
 */
function sentMails(): array
{
    return app('mail.manager')->mailer()->getSymfonyTransport()->messages()->map(function ($sent): array {
        $email = $sent->getOriginalMessage();
        $body = (string) $email->getHtmlBody();
        preg_match('#/invitation/([A-Za-z0-9]{48})#', $body, $match);

        return ['to' => $email->getTo()[0]->getAddress(), 'subject' => (string) $email->getSubject(), 'body' => $body, 'token' => $match[1] ?? null];
    })->all();
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount(['signup_plan' => 'business', 'name' => 'Salon Aurore', 'locale' => 'fr'], onboarded: false);
    $this->asOwner(function (): void {
        Service::query()->create(['org_id' => $this->org->id, 'name' => 'Coupe', 'duration_minutes' => 30, 'price_cents' => 2500]);
    });

    $this->as = fn () => $this->signedIn($this->owner, $this->org);
    $this->invite = function (array $override = []) {
        $response = ($this->as)()->post('/configuration/praticiens/invitation', array_merge(['name' => 'Léa Bernard', 'email' => 'lea@example.com'], $override));

        // Dans un test l'application est partagée entre les requêtes : le propriétaire ne reste pas « connecté » pour la suite,
        // car les pages d'invitation sont publiques (visiteur non connecté).
        auth()->forgetGuards();

        return $response;
    };
    $this->token = function (): string {
        $mails = sentMails();

        return (string) end($mails)['token'];
    };
    $this->hash = fn (string $token) => Invitation::hash($token);
});

describe('envoi de l\'invitation', function (): void {
    it('crée le praticien et l\'invitation, valable 3 jours', function (): void {
        ($this->invite)()->assertSessionHasNoErrors()->assertSessionHas('status', 'Invitation envoyée.');

        $invitation = $this->asOwner(fn () => Invitation::query()->firstOrFail());
        expect($invitation->email)->toBe('lea@example.com')
            ->and($invitation->used_at)->toBeNull()
            ->and($invitation->revoked_at)->toBeNull()
            ->and($invitation->expires_at->between(now()->addDays(3)->subMinute(), now()->addDays(3)->addMinute()))->toBeTrue()
            ->and(config('nova.security.invitation_ttl_days'))->toBe(3);

        $practitioner = $this->asOwner(fn () => Practitioner::query()->firstOrFail());
        expect($practitioner->name)->toBe('Léa Bernard')
            ->and($practitioner->status)->toBe('active')
            ->and($practitioner->user_id)->toBeNull()
            ->and($this->asOwner(fn () => DB::table('practitioner_services')->count()))->toBe(0)
            ->and($this->asOwner(fn () => PractitionerSchedule::query()->count()))->toBe(0);
    });

    it('envoie l\'e-mail à l\'adresse invitée, dans la langue de l\'entreprise, avec le lien', function (string $locale, string $subject): void {
        $this->asOwner(fn () => $this->org->forceFill(['locale' => $locale])->save());

        ($this->invite)();

        $mails = sentMails();
        expect($mails)->toHaveCount(1)
            ->and($mails[0]['to'])->toBe('lea@example.com')
            ->and($mails[0]['subject'])->toBe($subject)
            ->and($mails[0]['token'])->toMatch('/^[A-Za-z0-9]{48}$/')
            ->and($mails[0]['body'])->toContain('Léa Bernard')->toContain('3');
    })->with([
        ['fr', 'Salon Aurore vous invite sur Nova'],
        ['nl', 'Salon Aurore nodigt u uit op Nova'],
        ['en', 'Salon Aurore invites you to Nova'],
    ]);

    it('ne stocke jamais le jeton en clair (empreinte SHA-256 uniquement)', function (): void {
        ($this->invite)();
        $token = ($this->token)();

        $stored = $this->asOwner(fn () => DB::table('invitations')->value('token_hash'));
        expect($stored)->toBe(hash('sha256', $token))->toHaveLength(64)->not->toContain($token);

        $dump = json_encode($this->asOwner(fn () => DB::table('invitations')->get()));
        expect($dump)->not->toContain($token);
    });

    it('trace l\'envoi sans le jeton', function (): void {
        ($this->invite)();
        $token = ($this->token)();

        $log = $this->asOwner(fn () => DB::table('audit_logs')->where('action', 'invitation.sent')->first());
        expect(json_decode($log->diff, true))->toBe(['email' => 'lea@example.com'])
            ->and(json_encode($log))->not->toContain($token);
    });

    it('normalise l\'adresse e-mail en minuscules', function (): void {
        ($this->invite)(['email' => '  LEA@Example.COM ']);

        expect($this->asOwner(fn () => Invitation::query()->value('email')))->toBe('lea@example.com');
    });

    it('attribue une couleur différente à chaque praticien invité', function (): void {
        ($this->invite)(['email' => 'a@example.com']);
        ($this->invite)(['email' => 'b@example.com', 'name' => 'Second']);

        expect($this->asOwner(fn () => Practitioner::query()->pluck('color')->unique()->count()))->toBe(2);
    });

    it('refuse les données invalides', function (array $override, string $field): void {
        ($this->invite)($override)->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => Invitation::query()->count()))->toBe(0)->and(sentMails())->toBeEmpty();
    })->with([
        'nom vide' => [['name' => ''], 'name'],
        'nom trop court' => [['name' => 'L'], 'name'],
        'e-mail vide' => [['email' => ''], 'email'],
        'e-mail invalide' => [['email' => 'pas-un-email'], 'email'],
    ]);

    it('refuse une adresse déjà associée à un compte', function (): void {
        $this->asOwner(fn () => User::factory()->create(['email' => 'lea@example.com']));

        ($this->invite)()->assertSessionHasErrors(['email' => 'Cette adresse est déjà associée à un compte Nova, ou une invitation est déjà en attente.']);
    });

    it('refuse une seconde invitation en attente pour la même adresse', function (): void {
        ($this->invite)();

        ($this->invite)(['name' => 'Autre nom', 'email' => 'LEA@example.com'])->assertSessionHasErrors('email');

        expect($this->asOwner(fn () => Practitioner::query()->count()))->toBe(1);
    });

    it('crée le praticien en brouillon au-delà de la limite (E8), en envoyant quand même l\'invitation', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['signup_plan' => 'basic'])->save());
        ($this->invite)(['email' => 'a@example.com', 'name' => 'Premier']);

        ($this->invite)(['email' => 'b@example.com', 'name' => 'Second'])->assertSessionHas('warning', 'Votre plan inclut 1 praticien(s). Passez au plan supérieur pour en ajouter.');

        expect($this->asOwner(fn () => Practitioner::query()->where('name', 'Second')->value('status')))->toBe('draft')
            ->and(sentMails())->toHaveCount(2);
    });

    it('est réservé au propriétaire', function (string $role): void {
        ['user' => $user, 'org' => $org] = $this->activatedAccount(email: "{$role}@example.com", role: $role);

        $this->signedIn($user, $org)->post('/configuration/praticiens/invitation', ['name' => 'Léa', 'email' => 'lea@example.com'])->assertForbidden();

        expect(sentMails())->toBeEmpty();
    })->with(['site_manager', 'practitioner', 'receptionist']);

    it('affiche l\'invitation en attente sur la page des praticiens', function (): void {
        ($this->invite)();

        ($this->as)()->get('/configuration/assistant/praticiens')->assertInertia(fn (Assert $page) => $page
            ->where('invitationDays', 3)
            ->where('practitioners.0.invitation.email', 'lea@example.com')
            ->where('practitioners.0.invitation.status', 'pending')
            ->where('practitioners.0.has_account', false)
            ->where('practitioners.0.bookable', false));
    });

    it('signale une invitation expirée et permet de la renvoyer', function (): void {
        ($this->invite)();
        $this->travel(4)->days();

        ($this->as)()->get('/configuration/assistant/praticiens')->assertInertia(fn (Assert $page) => $page->where('practitioners.0.invitation.status', 'expired'));
    });
});

describe('page d\'invitation et erreur E13', function (): void {
    beforeEach(function (): void {
        ($this->invite)();
        $this->link = '/invitation/'.($this->token)();
    });

    it('affiche la page d\'acceptation avec l\'entreprise, le nom et l\'adresse', function (): void {
        $this->get($this->link)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Invitation/Accept')
            ->where('organization', 'Salon Aurore')
            ->where('name', 'Léa Bernard')
            ->where('email', 'lea@example.com')
            ->has('token'));
    });

    it('reste valable presque 3 jours puis expire (E13)', function (): void {
        $this->travel(2)->days();
        $this->travel(23)->hours();
        $this->get($this->link)->assertInertia(fn (Assert $page) => $page->component('Invitation/Accept'));

        $this->travel(2)->hours();
        $this->get($this->link)->assertInertia(fn (Assert $page) => $page->component('Invitation/Expired'));
    });

    it('affiche le même écran pour un jeton inconnu, expiré, utilisé ou révoqué', function (string $case): void {
        $token = ($this->token)();
        $link = match ($case) {
            'inconnu' => '/invitation/'.str_repeat('a', 48),
            'trop court' => '/invitation/abc',
            default => "/invitation/{$token}",
        };

        match ($case) {
            'expiré' => $this->asOwner(fn () => Invitation::query()->update(['expires_at' => now()->subMinute()])),
            'utilisé' => $this->asOwner(fn () => Invitation::query()->update(['used_at' => now()])),
            'révoqué' => $this->asOwner(fn () => Invitation::query()->update(['revoked_at' => now()])),
            default => null,
        };

        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->component('Invitation/Expired'));
    })->with(['inconnu', 'trop court', 'expiré', 'utilisé', 'révoqué']);

    it('redirige un utilisateur déjà connecté vers son tableau de bord', function (): void {
        ($this->as)()->get($this->link)->assertRedirect('/tableau-de-bord');
    });

    it('ne révèle rien sur les jetons : la fonction ne renvoie que la ligne demandée', function (): void {
        $token = ($this->token)();

        expect(DB::select('select * from invitation_lookup(?)', [$this->hash->__invoke($token)]))->toHaveCount(1)
            ->and(DB::select('select * from invitation_lookup(?)', [hash('sha256', 'autre')]))->toBeEmpty();

        // Sans contexte d'organisation, la table elle-même reste invisible pour le rôle client.
        expect(Invitation::query()->count())->toBe(0);
    });
});

describe('renvoi de l\'invitation (E13)', function (): void {
    beforeEach(function (): void {
        ($this->invite)();
        $this->oldToken = ($this->token)();
        $this->practitioner = $this->asOwner(fn () => Practitioner::query()->firstOrFail());
        $this->resend = function () {
            $response = ($this->as)()->post("/configuration/praticiens/{$this->practitioner->id}/invitation");
            auth()->forgetGuards(); // les pages d'invitation sont publiques : on revient à un visiteur non connecté

            return $response;
        };
    });

    it('génère un nouveau lien et invalide l\'ancien', function (): void {
        ($this->resend)()->assertSessionHasNoErrors()->assertSessionHas('status');
        $newToken = ($this->token)();

        expect($newToken)->not->toBe($this->oldToken)->and(sentMails())->toHaveCount(2);

        $this->get("/invitation/{$this->oldToken}")->assertInertia(fn (Assert $page) => $page->component('Invitation/Expired'));
        $this->get("/invitation/{$newToken}")->assertInertia(fn (Assert $page) => $page->component('Invitation/Accept'));

        expect($this->asOwner(fn () => Invitation::query()->whereNull('revoked_at')->whereNull('used_at')->count()))->toBe(1)
            ->and($this->asOwner(fn () => Invitation::query()->whereNotNull('revoked_at')->count()))->toBe(1);
    });

    it('permet de renvoyer une invitation expirée', function (): void {
        $this->travel(4)->days();

        ($this->resend)()->assertSessionHasNoErrors();

        $this->get('/invitation/'.($this->token)())->assertInertia(fn (Assert $page) => $page->component('Invitation/Accept'));
    });

    it('envoie à la même adresse', function (): void {
        ($this->resend)();

        expect(sentMails()[1]['to'])->toBe('lea@example.com');
    });

    it('refuse de ré-inviter un praticien qui a déjà un compte', function (): void {
        $this->asOwner(fn () => $this->practitioner->forceFill(['user_id' => $this->owner->id])->save());

        ($this->resend)()->assertSessionHasErrors(['practitioner' => 'Ce praticien ne peut pas être ré-invité.']);
    });

    it('refuse de ré-inviter un praticien créé sans invitation', function (): void {
        $this->asOwner(fn () => Invitation::query()->delete());

        ($this->resend)()->assertSessionHasErrors('practitioner');
    });

    it('répond 404 pour le praticien d\'un autre client', function (): void {
        ['user' => $otherOwner, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $this->signedIn($otherOwner, $otherOrg)->post("/configuration/praticiens/{$this->practitioner->id}/invitation")->assertNotFound();
    });
});

describe('acceptation', function (): void {
    beforeEach(function (): void {
        ($this->invite)();
        $this->token = ($this->token)();
        $this->accept = fn (array $override = [], ?string $token = null) => $this->post('/invitation/'.($token ?? $this->token), array_merge([
            'password' => 'Sol1de-Phrase-Pour-Test!', 'password_confirmation' => 'Sol1de-Phrase-Pour-Test!', 'accept_terms' => true, 'accept_privacy' => true,
        ], $override));
    });

    it('crée le compte, le rattache à l\'organisation et connecte le praticien', function (): void {
        ($this->accept)()->assertRedirect('/mon-profil');

        $user = $this->asOwner(fn () => User::query()->where('email', 'lea@example.com')->firstOrFail());
        expect($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->phone_e164)->toBeNull()
            ->and($user->locale)->toBe('fr')
            ->and(Hash::check('Sol1de-Phrase-Pour-Test!', $user->password))->toBeTrue();

        $member = $this->asOwner(fn () => DB::table('org_members')->where('user_id', $user->id)->first());
        expect($member->org_id)->toBe($this->org->id)->and($member->role)->toBe('practitioner')->and($member->status)->toBe('active');

        expect($this->asOwner(fn () => Practitioner::query()->firstOrFail()->user_id))->toBe($user->id)
            ->and($this->asOwner(fn () => Invitation::query()->firstOrFail()->used_at))->not->toBeNull()
            ->and($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'invitation.accepted')->where('org_id', $this->org->id)->count()))->toBe(1);

        $this->assertAuthenticatedAs($user);
        expect(session(SetTenantContext::SESSION_KEY))->toBe($this->org->id);
    });

    it('utilise la langue de l\'entreprise pour le compte créé', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['locale' => 'nl'])->save());

        ($this->accept)();

        expect($this->asOwner(fn () => User::query()->where('email', 'lea@example.com')->value('locale')))->toBe('nl');
    });

    it('donne accès à l\'espace du praticien, pas à celui du propriétaire', function (): void {
        ($this->accept)();

        $this->get('/mon-profil')->assertOk();
        $this->get('/configuration/assistant')->assertForbidden();
        $this->post('/configuration/praticiens/invitation', ['name' => 'X Y', 'email' => 'x@example.com'])->assertForbidden();
    });

    it('n\'impose ni vérification du téléphone ni assistant à la connexion suivante', function (): void {
        ($this->accept)();
        $this->post('/deconnexion');

        $this->post('/connexion', ['email' => 'lea@example.com', 'password' => 'Sol1de-Phrase-Pour-Test!'])->assertRedirect('/tableau-de-bord');
    });

    it('refuse un mot de passe faible, non confirmé, ou des conditions non acceptées, sans consommer le lien', function (array $override, string $field): void {
        ($this->accept)($override)->assertSessionHasErrors($field);

        expect($this->asOwner(fn () => User::query()->where('email', 'lea@example.com')->count()))->toBe(0)
            ->and($this->asOwner(fn () => Invitation::query()->firstOrFail()->used_at))->toBeNull();
        $this->assertGuest();
    })->with([
        'trop court' => [['password' => 'court1', 'password_confirmation' => 'court1'], 'password'],
        'sans chiffre' => [['password' => 'uniquement-des-lettres-longues', 'password_confirmation' => 'uniquement-des-lettres-longues'], 'password'],
        'confirmation différente' => [['password_confirmation' => 'autre-chose-que-le-mot-de-passe-9'], 'password'],
        'CGU refusées' => [['accept_terms' => false], 'accept_terms'],
        'confidentialité refusée' => [['accept_privacy' => false], 'accept_privacy'],
    ]);

    it('ne peut être acceptée qu\'une seule fois', function (): void {
        ($this->accept)()->assertRedirect('/mon-profil');
        $this->post('/deconnexion');

        ($this->accept)()->assertRedirect("/invitation/{$this->token}");

        expect($this->asOwner(fn () => User::query()->where('email', 'lea@example.com')->count()))->toBe(1)
            ->and($this->asOwner(fn () => DB::table('org_members')->where('role', 'practitioner')->count()))->toBe(1);
    });

    it('refuse un lien expiré, révoqué ou inconnu sans créer de compte', function (string $case): void {
        match ($case) {
            'expiré' => $this->asOwner(fn () => Invitation::query()->update(['expires_at' => now()->subMinute()])),
            'révoqué' => $this->asOwner(fn () => Invitation::query()->update(['revoked_at' => now()])),
            default => null,
        };

        ($this->accept)([], $case === 'inconnu' ? str_repeat('b', 48) : null)->assertRedirect();

        expect($this->asOwner(fn () => User::query()->where('email', 'lea@example.com')->count()))->toBe(0)
            ->and($this->asOwner(fn () => DB::table('org_members')->where('role', 'practitioner')->count()))->toBe(0);
        $this->assertGuest();
    })->with(['expiré', 'révoqué', 'inconnu']);

    it('refuse un lien remplacé par une nouvelle invitation', function (): void {
        $practitioner = $this->asOwner(fn () => Practitioner::query()->firstOrFail());
        ($this->as)()->post("/configuration/praticiens/{$practitioner->id}/invitation");
        auth()->forgetGuards();

        ($this->accept)()->assertRedirect("/invitation/{$this->token}");

        expect($this->asOwner(fn () => User::query()->where('email', 'lea@example.com')->count()))->toBe(0);
    });

    it('libère le contexte d\'organisation après l\'acceptation', function (): void {
        ($this->accept)();

        expect(app(TenantContext::class)->current())->toBeNull();
    });

    it('n\'accède qu\'à l\'organisation qui a invité', function (): void {
        ['org' => $other] = $this->activatedAccount(email: 'autre@example.com');

        ($this->accept)();

        $orgs = $this->asOwner(fn () => DB::table('org_members')->join('users', 'users.id', '=', 'org_members.user_id')->where('users.email', 'lea@example.com')->pluck('org_members.org_id')->all());
        expect($orgs)->toBe([$this->org->id])->not->toContain($other->id);
    });
});

describe('tableau de bord du praticien invité', function (): void {
    it('l\'invite à compléter son profil tant qu\'il n\'est pas réservable', function (): void {
        ($this->invite)();
        $this->post('/invitation/'.($this->token)(), [
            'password' => 'Sol1de-Phrase-Pour-Test!', 'password_confirmation' => 'Sol1de-Phrase-Pour-Test!', 'accept_terms' => true, 'accept_privacy' => true,
        ]);

        $this->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('profile.complete', false)->where('onboarding', null));
    });

    it('n\'affiche pas ce bandeau au propriétaire', function (): void {
        ($this->as)()->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page->where('profile', null));
    });
});
