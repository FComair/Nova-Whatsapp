<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SuspiciousLoginNotification;
use App\Modules\Tenancy\Http\Middleware\EnsureOrganizationUsable;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Flowchart 2A (connexion) et 5A/E3 (verrouillage). Le mot de passe de test des factories est « password-de-test ».
 */
const GOOD_PASSWORD = 'password-de-test';

beforeEach(function (): void {
    ['user' => $this->user, 'org' => $this->org] = $this->activatedAccount();
});

describe('connexion', function (): void {
    it('affiche la page de connexion', function (): void {
        $this->get('/connexion')->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
    });

    it('connecte l\'utilisateur, rattache son organisation et l\'envoie au tableau de bord', function (): void {
        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD])->assertRedirect('/tableau-de-bord');

        $this->assertAuthenticatedAs($this->user);
        expect(session(SetTenantContext::SESSION_KEY))->toBe($this->org->id);

        $this->get('/tableau-de-bord')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('auth.organization.id', $this->org->id)
            ->where('auth.organization.role', 'owner')
            ->where('auth.user.email', 'proprio@example.com'));
    });

    it('accepte l\'e-mail quelle que soit sa casse', function (): void {
        $this->post('/connexion', ['email' => 'PROPRIO@Example.COM', 'password' => GOOD_PASSWORD])->assertRedirect('/tableau-de-bord');
    });

    it('donne le même message pour un mauvais mot de passe et un e-mail inconnu', function (): void {
        $wrongPassword = $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'faux-mot-de-passe-1']);
        $unknownEmail = $this->post('/connexion', ['email' => 'personne@example.com', 'password' => 'faux-mot-de-passe-1']);

        $wrongPassword->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
        $unknownEmail->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
        $this->assertGuest();
    });

    it('n\'ouvre pas de session pour un compte dont l\'e-mail n\'est pas vérifié', function (): void {
        $this->asOwner(fn () => $this->user->forceFill(['email_verified_at' => null])->save());

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD])->assertRedirect('/verification-email');
        $this->assertGuest();
    });

    it('n\'ouvre pas de session pour un compte dont le téléphone n\'est pas vérifié', function (): void {
        $this->asOwner(fn () => $this->user->forceFill(['phone_verified_at' => null])->save());

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD])->assertRedirect('/verification-telephone');
        $this->assertGuest();
    });

    it('refuse un compte désactivé sans le révéler', function (): void {
        $this->asOwner(fn () => $this->user->forceFill(['status' => 'disabled'])->save());

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD])->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
        $this->assertGuest();
    });

    it('refuse un compte sans rattachement actif à une organisation', function (): void {
        $this->asOwner(fn () => DB::table('org_members')->where('user_id', $this->user->id)->update(['status' => 'disabled']));

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD])->assertRedirect('/connexion');
        $this->assertGuest();
    });

    it('déconnecte et invalide la session', function (): void {
        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD]);

        $this->post('/deconnexion')->assertRedirect('/connexion');
        $this->assertGuest();
        $this->get('/tableau-de-bord')->assertRedirect('/connexion');
    });

    it('renvoie les visiteurs non connectés vers la connexion', function (): void {
        $this->get('/tableau-de-bord')->assertRedirect('/connexion');
    });

    it('trace la connexion dans le journal d\'audit de l\'organisation', function (): void {
        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD]);

        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'auth.login')->where('org_id', $this->org->id)->count()))->toBe(1);
    });
});

describe('verrouillage après échecs (E3)', function (): void {
    it('verrouille 15 minutes après 5 échecs et prévient le titulaire par e-mail', function (): void {
        Notification::fake();

        for ($i = 1; $i <= 4; $i++) {
            $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'faux-mot-de-passe-1'])
                ->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
        }

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'faux-mot-de-passe-1'])
            ->assertSessionHasErrors(['email' => 'Trop de tentatives. Réessayez dans 15 minutes ou réinitialisez votre mot de passe.']);

        Notification::assertSentTo($this->user, SuspiciousLoginNotification::class);
        expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'auth.login.locked')->count()))->toBe(1);
    });

    it('refuse même le bon mot de passe pendant le verrouillage', function (): void {
        Notification::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'faux-mot-de-passe-1']);
        }

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD])
            ->assertSessionHasErrors(['email' => 'Trop de tentatives. Réessayez dans 15 minutes ou réinitialisez votre mot de passe.']);

        $this->assertGuest();
    });

    it('déverrouille automatiquement après 15 minutes', function (): void {
        Notification::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'faux-mot-de-passe-1']);
        }

        $this->travel(16)->minutes();

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD])->assertRedirect('/tableau-de-bord');
    });

    it('remet le compteur d\'échecs à zéro après une connexion réussie', function (): void {
        for ($i = 1; $i <= 4; $i++) {
            $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'faux-mot-de-passe-1']);
        }

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => GOOD_PASSWORD])->assertRedirect('/tableau-de-bord');
        $this->post('/deconnexion');

        expect($this->asOwner(fn () => User::query()->find($this->user->id)->failed_login_count))->toBe(0);
    });

    it('applique la même limite aux e-mails inconnus, sans révéler qu\'ils n\'existent pas', function (): void {
        for ($i = 1; $i <= 4; $i++) {
            $this->post('/connexion', ['email' => 'fantome@example.com', 'password' => 'faux-mot-de-passe-1'])
                ->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
        }

        $this->post('/connexion', ['email' => 'fantome@example.com', 'password' => 'faux-mot-de-passe-1'])
            ->assertSessionHasErrors(['email' => 'Trop de tentatives. Réessayez dans 15 minutes ou réinitialisez votre mot de passe.']);
    });

    it('rend le message de verrouillage dans la langue de l\'interface', function (string $locale, string $expected): void {
        Notification::fake();

        for ($i = 1; $i <= 4; $i++) {
            $this->withSession(['locale' => $locale])->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'faux-mot-de-passe-1']);
        }

        $this->withSession(['locale' => $locale])->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'faux-mot-de-passe-1'])
            ->assertSessionHasErrors(['email' => $expected]);
    })->with([
        ['fr', 'Trop de tentatives. Réessayez dans 15 minutes ou réinitialisez votre mot de passe.'],
        ['nl', 'Te veel pogingen. Probeer het opnieuw over 15 minuten of stel uw wachtwoord opnieuw in.'],
        ['en', 'Too many attempts. Try again in 15 minutes or reset your password.'],
    ]);
});

describe('contexte d\'organisation et statut du compte', function (): void {
    beforeEach(function (): void {
        // Route d'écriture factice, protégée par la même pile de middlewares que l'espace connecté.
        Route::middleware(['web', 'auth', AuthenticateSession::class, SetTenantContext::class, EnsureOrganizationUsable::class])
            ->post('/_test/ecriture', fn () => response('ecrit'))->name('test.write');

        $this->login = fn () => $this->withSession([SetTenantContext::SESSION_KEY => $this->org->id])->actingAs($this->user);
    });

    it('libère le contexte d\'organisation à la fin de la requête', function (): void {
        ($this->login)()->get('/tableau-de-bord')->assertOk();

        expect(app(TenantContext::class)->current())->toBeNull();
    });

    it('refuse une organisation trafiquée dans la session (revérifiée en base)', function (): void {
        $autre = $this->asOwner(fn () => Organization::factory()->create());

        $this->actingAs($this->user)->withSession([SetTenantContext::SESSION_KEY => $autre->id])
            ->get('/tableau-de-bord')->assertForbidden();

        expect(app(TenantContext::class)->current())->toBeNull();
    });

    it('refuse l\'accès sans organisation dans la session', function (): void {
        $this->actingAs($this->user)->get('/tableau-de-bord')->assertForbidden();
    });

    it('autorise lecture et écriture sur un compte actif ou en essai', function (): void {
        ($this->login)()->post('/_test/ecriture')->assertOk();
    });

    it('met le compte suspendu en lecture seule', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended', 'suspension_reason' => 'Paiement échoué'])->save());

        ($this->login)()->get('/tableau-de-bord')->assertOk();
        ($this->login)()->post('/_test/ecriture')->assertRedirect('/compte')->assertSessionHasErrors('account');
    });

    it('renvoie un compte en pause ou en suppression vers l\'écran d\'état', function (string $status): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => $status])->save());

        ($this->login)()->get('/tableau-de-bord')->assertRedirect('/compte');
        ($this->login)()->get('/compte')->assertOk()->assertInertia(fn (Assert $page) => $page->component('AccountState')->where('status', $status));
    })->with(['paused', 'pending_deletion']);

    it('interdit tout accès à un compte supprimé', function (): void {
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'deleted'])->save());

        ($this->login)()->get('/tableau-de-bord')->assertForbidden();
    });

    it('n\'expose à un utilisateur que son organisation dans les données partagées', function (): void {
        $autre = $this->asOwner(fn () => Organization::factory()->create(['name' => 'Concurrent SA']));

        ($this->login)()->get('/tableau-de-bord')->assertInertia(fn (Assert $page) => $page
            ->where('auth.organization.name', $this->org->name)
            ->missing('auth.organization.slug'));

        expect(json_encode(($this->login)()->get('/tableau-de-bord')->viewData('page')))->not->toContain('Concurrent SA');
    });
});
