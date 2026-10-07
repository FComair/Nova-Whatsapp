<?php

declare(strict_types=1);

use App\Modules\Identity\Contracts\PhoneCodeSender;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ConfirmNewEmailNotification;
use App\Modules\Identity\Notifications\EmailChangeNoticeNotification;
use App\Modules\Identity\Notifications\PasswordChangedNotification;
use App\Modules\Identity\Services\TwoFactor;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\AuthScenarios;
use Tests\Support\FakePhoneCodeSender;

uses(AuthScenarios::class);

/**
 * Paramètres > Sécurité (flowchart 3C) et double authentification (décision 18).
 */
const CURRENT_PASSWORD = 'password-de-test';

/** Code TOTP valide pour un pas de temps décalé (chaque code n'est accepté qu'une fois). */
function totp(string $secret, int $offset = 0): string
{
    $g = new Google2FA;

    return $g->oathTotp($secret, $g->getTimestamp() + $offset);
}

beforeEach(function (): void {
    ['user' => $this->user, 'org' => $this->org] = $this->activatedAccount();
});

function enableTwoFactorFor(User $user): array
{
    $service = app(TwoFactor::class);
    $secret = $service->beginSetup($user);

    return [$secret, $service->confirm($user->refresh(), totp($secret))];
}

describe('page Sécurité', function (): void {
    it('s\'affiche pour tous les rôles avec l\'état de la double authentification', function (): void {
        $this->signedIn($this->user, $this->org)->get('/securite')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Account/Security')
            ->where('email', 'proprio@example.com')
            ->where('twoFactor.enabled', false)
            ->where('twoFactor.setup', null));
    });

    it('exige d\'être connecté', function (): void {
        $this->get('/securite')->assertRedirect('/connexion');
    });
});

describe('double authentification : activation', function (): void {
    it('génère un secret et un code à scanner, sans activer', function (): void {
        $this->signedIn($this->user, $this->org)->post('/securite/2fa')->assertRedirect('/securite');

        $this->get('/securite')->assertInertia(fn (Assert $page) => $page
            ->where('twoFactor.enabled', false)
            ->where('twoFactor.setup.secret', fn (string $secret) => strlen($secret) === 32)
            ->where('twoFactor.setup.qr', fn (string $qr) => str_starts_with($qr, 'data:image/svg+xml')));
        expect($this->user->refresh()->hasTwoFactor())->toBeFalse();
    });

    it('refuse un mauvais code, puis active avec le bon et donne 8 codes de secours une seule fois', function (): void {
        $this->signedIn($this->user, $this->org)->post('/securite/2fa');
        $secret = $this->user->refresh()->two_factor_secret;

        $this->post('/securite/2fa/confirmer', ['code' => '000000'])->assertSessionHasErrors('code');
        expect($this->user->refresh()->hasTwoFactor())->toBeFalse();

        $this->post('/securite/2fa/confirmer', ['code' => totp($secret)])->assertRedirect('/securite')->assertSessionHas('recovery_codes', fn (array $codes) => count($codes) === 8);

        $this->get('/securite')->assertInertia(fn (Assert $page) => $page->where('twoFactor.enabled', true)->where('twoFactor.remainingCodes', 8)->has('recoveryCodes', 8));
        $this->get('/securite')->assertInertia(fn (Assert $page) => $page->where('recoveryCodes', null));
    });

    it('chiffre le secret et ne conserve que des empreintes des codes de secours', function (): void {
        [$secret, $codes] = enableTwoFactorFor($this->user);

        $row = DB::connection('pgsql_owner')->table('users')->where('id', $this->user->id)->first();

        expect($row->two_factor_secret)->not->toContain($secret)
            ->and(json_encode(Crypt::decrypt($row->two_factor_recovery_codes, false)))->not->toContain($codes[0]);
    });

    it('ne réaffiche pas le secret une fois la double authentification active', function (): void {
        enableTwoFactorFor($this->user);

        $this->signedIn($this->user, $this->org)->get('/securite')->assertInertia(fn (Assert $page) => $page->where('twoFactor.setup', null));
    });
});

describe('double authentification : connexion', function (): void {
    beforeEach(function (): void {
        [$this->secret, $this->codes] = enableTwoFactorFor($this->user);
    });

    it('demande le code après le mot de passe, sans ouvrir la session', function (): void {
        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => CURRENT_PASSWORD])->assertRedirect('/connexion/verification');

        $this->assertGuest();
        $this->get('/connexion/verification')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/TwoFactorChallenge'));
    });

    it('ouvre la session avec un code valide', function (): void {
        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => CURRENT_PASSWORD]);

        $this->post('/connexion/verification', ['code' => totp($this->secret, -1)])->assertRedirect('/tableau-de-bord');

        $this->assertAuthenticatedAs($this->user);
    });

    it('refuse un code faux sans ouvrir la session', function (): void {
        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => CURRENT_PASSWORD]);

        $this->post('/connexion/verification', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    });

    it('n\'accepte pas deux fois le même code (rejeu)', function (): void {
        $code = totp($this->secret, -1);

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => CURRENT_PASSWORD]);
        $this->post('/connexion/verification', ['code' => $code])->assertRedirect('/tableau-de-bord');
        $this->post('/deconnexion');

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => CURRENT_PASSWORD]);
        $this->post('/connexion/verification', ['code' => $code])->assertSessionHasErrors('code');
    });

    it('accepte un code de secours, une seule fois', function (): void {
        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => CURRENT_PASSWORD]);
        $this->post('/connexion/verification', ['code' => strtoupper($this->codes[0])])->assertRedirect('/tableau-de-bord');
        $this->post('/deconnexion');

        expect($this->user->refresh()->two_factor_recovery_codes)->toHaveCount(7);

        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => CURRENT_PASSWORD]);
        $this->post('/connexion/verification', ['code' => $this->codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('abandonne la connexion après 5 codes faux', function (): void {
        $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => CURRENT_PASSWORD]);

        foreach (range(1, 4) as $_) {
            $this->post('/connexion/verification', ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->post('/connexion/verification', ['code' => '000000'])->assertRedirect('/connexion');
        $this->post('/connexion/verification', ['code' => totp($this->secret, -1)])->assertRedirect('/connexion');
        $this->assertGuest();
    });

    it('renvoie vers la connexion sans mot de passe préalable', function (): void {
        $this->get('/connexion/verification')->assertRedirect('/connexion');
        $this->post('/connexion/verification', ['code' => totp($this->secret, -1)])->assertRedirect('/connexion');
    });

    it('ne change rien pour un compte sans double authentification', function (): void {
        ['user' => $other] = $this->activatedAccount(email: 'autre@example.com');

        $this->post('/connexion', ['email' => $other->email, 'password' => CURRENT_PASSWORD])->assertRedirect('/tableau-de-bord');
    });
});

describe('double authentification obligatoire (propriétaire et responsables)', function (): void {
    beforeEach(fn () => config(['nova.security.two_factor_required' => true]));

    it('enferme le propriétaire sur la page Sécurité tant qu\'elle n\'est pas activée, sans délai', function (string $uri): void {
        $this->signedIn($this->user, $this->org)->get($uri)->assertRedirect('/securite');
    })->with(['/tableau-de-bord', '/planning', '/clients', '/facturation', '/calendriers']);

    it('laisse la page Sécurité, la déconnexion et le changement de langue', function (): void {
        $this->signedIn($this->user, $this->org)->get('/securite')->assertOk();
        $this->post('/langue', ['locale' => 'nl'])->assertRedirect();
        $this->post('/deconnexion')->assertRedirect('/connexion');
    });

    it('s\'applique aussi aux responsables de site', function (): void {
        ['user' => $manager, 'org' => $managerOrg] = $this->activatedAccount(email: 'resp@example.com', role: 'site_manager');

        $this->signedIn($manager, $managerOrg)->get('/planning')->assertRedirect('/securite');
    });

    it('ne touche ni les praticiens ni les réceptionnistes', function (string $role): void {
        ['user' => $member, 'org' => $org] = $this->activatedAccount(email: 'membre@example.com', role: $role);

        $this->signedIn($member, $org)->get('/planning')->assertOk();
    })->with(['practitioner', 'receptionist']);

    it('laisse passer une fois la double authentification activée', function (): void {
        enableTwoFactorFor($this->user);

        $this->signedIn($this->user, $this->org)->get('/planning')->assertOk();
    });

    it('ne peut pas être désactivée par ces rôles', function (): void {
        enableTwoFactorFor($this->user);

        $this->signedIn($this->user, $this->org)->delete('/securite/2fa', ['current_password' => CURRENT_PASSWORD, 'code' => totp($this->user->refresh()->two_factor_secret, 1)])->assertSessionHasErrors('two_factor');

        expect($this->user->refresh()->hasTwoFactor())->toBeTrue();
    });
});

describe('double authentification : désactivation et codes de secours', function (): void {
    beforeEach(function (): void {
        ['user' => $this->member, 'org' => $this->memberOrg] = $this->activatedAccount(email: 'prat@example.com', role: 'practitioner');
        [$this->secret] = enableTwoFactorFor($this->member);
    });

    it('se désactive avec le mot de passe et un code', function (): void {
        $this->signedIn($this->member, $this->memberOrg)->delete('/securite/2fa', ['current_password' => CURRENT_PASSWORD, 'code' => totp($this->secret, 1)])->assertSessionHasNoErrors();

        expect($this->member->refresh()->hasTwoFactor())->toBeFalse()->and($this->member->two_factor_secret)->toBeNull();
    });

    it('refuse sans le bon mot de passe ou sans code valide', function (): void {
        $this->signedIn($this->member, $this->memberOrg);

        $this->delete('/securite/2fa', ['current_password' => 'faux', 'code' => totp($this->secret, 1)])->assertSessionHasErrors('current_password');
        $this->delete('/securite/2fa', ['current_password' => CURRENT_PASSWORD, 'code' => '000000'])->assertSessionHasErrors('code');

        expect($this->member->refresh()->hasTwoFactor())->toBeTrue();
    });

    it('renouvelle les codes de secours : les anciens cessent de fonctionner', function (): void {
        $old = $this->member->two_factor_recovery_codes;

        $this->signedIn($this->member, $this->memberOrg)->post('/securite/2fa/codes', ['current_password' => CURRENT_PASSWORD])->assertSessionHas('recovery_codes', fn (array $codes) => count($codes) === 8);

        expect($this->member->refresh()->two_factor_recovery_codes)->not->toBe($old);
    });

    it('exige le mot de passe pour renouveler les codes', function (): void {
        $this->signedIn($this->member, $this->memberOrg)->post('/securite/2fa/codes', ['current_password' => 'faux'])->assertSessionHasErrors('current_password');
    });
});

describe('mot de passe', function (): void {
    it('change le mot de passe, prévient par e-mail et mémorise l\'historique', function (): void {
        Notification::fake();
        $before = $this->user->password_changed_at;
        $this->travel(1)->day();

        $this->signedIn($this->user, $this->org)->put('/securite/mot-de-passe', ['current_password' => CURRENT_PASSWORD, 'password' => 'Nouv3au-Phrase-Robuste!9', 'password_confirmation' => 'Nouv3au-Phrase-Robuste!9'])->assertSessionHasNoErrors();

        $this->user->refresh();
        expect(Hash::check('Nouv3au-Phrase-Robuste!9', $this->user->password))->toBeTrue()
            ->and($this->user->password_changed_at->gt($before))->toBeTrue();
        Notification::assertSentTo($this->user, PasswordChangedNotification::class);
    });

    it('refuse un mauvais mot de passe actuel', function (): void {
        $this->signedIn($this->user, $this->org)->put('/securite/mot-de-passe', ['current_password' => 'faux', 'password' => 'Nouv3au-Phrase-Robuste!9', 'password_confirmation' => 'Nouv3au-Phrase-Robuste!9'])->assertSessionHasErrors('current_password');

        expect(Hash::check(CURRENT_PASSWORD, $this->user->refresh()->password))->toBeTrue();
    });

    it('applique la politique : faible, évident, non confirmé, déjà utilisé', function (array $override): void {
        $this->signedIn($this->user, $this->org)->put('/securite/mot-de-passe', array_merge(['current_password' => CURRENT_PASSWORD, 'password' => 'Nouv3au-Phrase-Robuste!9', 'password_confirmation' => 'Nouv3au-Phrase-Robuste!9'], $override))->assertSessionHasErrors('password');
    })->with([
        'faible' => [['password' => 'court1', 'password_confirmation' => 'court1']],
        'évident' => [['password' => 'P@ssw0rd-2026-xx', 'password_confirmation' => 'P@ssw0rd-2026-xx']],
        'non confirmé' => [['password_confirmation' => 'Autre-Phrase-Robuste!1']],
    ]);

    it('refuse le mot de passe actuel comme nouveau mot de passe', function (): void {
        $this->user->forceFill(['password' => 'Actuel-Mdp-Solide1!'])->save();

        $this->signedIn($this->user, $this->org)->put('/securite/mot-de-passe', ['current_password' => 'Actuel-Mdp-Solide1!', 'password' => 'Actuel-Mdp-Solide1!', 'password_confirmation' => 'Actuel-Mdp-Solide1!'])->assertSessionHasErrors('password');
    });

    it('bloque après 5 mots de passe actuels faux', function (): void {
        $this->signedIn($this->user, $this->org);

        foreach (range(1, 5) as $_) {
            $this->put('/securite/mot-de-passe', ['current_password' => 'faux', 'password' => 'Nouv3au-Phrase-Robuste!9', 'password_confirmation' => 'Nouv3au-Phrase-Robuste!9']);
        }

        $this->put('/securite/mot-de-passe', ['current_password' => CURRENT_PASSWORD, 'password' => 'Nouv3au-Phrase-Robuste!9', 'password_confirmation' => 'Nouv3au-Phrase-Robuste!9'])->assertSessionHasErrors('current_password');

        expect(Hash::check(CURRENT_PASSWORD, $this->user->refresh()->password))->toBeTrue();
    });

    it('recommande de le changer au bout de 12 mois', function (): void {
        $this->user->forceFill(['password_changed_at' => now()->subMonths(13)])->save();

        $this->signedIn($this->user, $this->org)->get('/securite')->assertInertia(fn (Assert $page) => $page->where('passwordOld', true));
    });

    it('ne le signale pas pour un mot de passe récent', function (): void {
        $this->signedIn($this->user, $this->org)->get('/securite')->assertInertia(fn (Assert $page) => $page->where('passwordOld', false));
    });
});

describe('sessions', function (): void {
    it('ferme les autres sessions sans toucher au mot de passe ni à son historique', function (): void {
        $hash = $this->user->password;
        $count = DB::table('password_histories')->where('user_id', $this->user->id)->count();

        $this->signedIn($this->user, $this->org)->post('/securite/sessions/fermer', ['current_password' => CURRENT_PASSWORD])->assertSessionHasNoErrors();

        $this->user->refresh();
        expect(Hash::check(CURRENT_PASSWORD, $this->user->password))->toBeTrue()
            ->and(DB::table('password_histories')->where('user_id', $this->user->id)->count())->toBe($count)
            ->and($this->user->password)->not->toBe($hash);
    });

    it('exige le mot de passe actuel', function (): void {
        $this->signedIn($this->user, $this->org)->post('/securite/sessions/fermer', ['current_password' => 'faux'])->assertSessionHasErrors('current_password');
    });
});

describe('adresse e-mail', function (): void {
    it('envoie le lien à la NOUVELLE adresse et un avis à l\'ancienne, sans rien changer', function (): void {
        Notification::fake();

        $this->signedIn($this->user, $this->org)->post('/securite/email', ['current_password' => CURRENT_PASSWORD, 'email' => 'Nouvelle@Example.com'])->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(ConfirmNewEmailNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'nouvelle@example.com');
        Notification::assertSentTo($this->user, EmailChangeNoticeNotification::class);
        expect($this->user->refresh()->email)->toBe('proprio@example.com');

        $this->get('/securite')->assertInertia(fn (Assert $page) => $page->where('pendingEmail', 'nouvelle@example.com'));
    });

    it('refuse un mauvais mot de passe, une adresse déjà utilisée ou identique', function (): void {
        $this->activatedAccount(email: 'pris@example.com', role: 'practitioner');
        $this->signedIn($this->user, $this->org);

        $this->post('/securite/email', ['current_password' => 'faux', 'email' => 'nouvelle@example.com'])->assertSessionHasErrors('current_password');
        $this->post('/securite/email', ['current_password' => CURRENT_PASSWORD, 'email' => 'pris@example.com'])->assertSessionHasErrors('email');
        $this->post('/securite/email', ['current_password' => CURRENT_PASSWORD, 'email' => 'proprio@example.com'])->assertSessionHasErrors('email');
    });

    it('change l\'adresse à la confirmation, la considère vérifiée et prévient l\'ancienne', function (): void {
        $tokens = [];
        Notification::fake();
        $this->signedIn($this->user, $this->org)->post('/securite/email', ['current_password' => CURRENT_PASSWORD, 'email' => 'nouvelle@example.com']);
        Notification::assertSentOnDemand(ConfirmNewEmailNotification::class, function ($notification) use (&$tokens) {
            $tokens[] = (new ReflectionProperty($notification, 'token'))->getValue($notification);

            return true;
        });

        $this->get(URL::temporarySignedRoute('security.email.confirm', now()->addHour(), ['token' => $tokens[0]]))->assertRedirect('/securite')->assertSessionHas('status');

        expect($this->user->refresh()->email)->toBe('nouvelle@example.com')->and($this->user->email_verified_at)->not->toBeNull();
        Notification::assertSentOnDemand(EmailChangeNoticeNotification::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'proprio@example.com');
        expect(DB::table('email_changes')->where('user_id', $this->user->id)->exists())->toBeFalse();
    });

    it('refuse un lien non signé, falsifié, expiré ou d\'un autre compte', function (): void {
        Notification::fake();
        $this->signedIn($this->user, $this->org)->post('/securite/email', ['current_password' => CURRENT_PASSWORD, 'email' => 'nouvelle@example.com']);

        $this->get('/securite/email/confirmer/nimportequoi')->assertForbidden();
        $this->get(URL::temporarySignedRoute('security.email.confirm', now()->addHour(), ['token' => 'faux']))->assertRedirect('/securite')->assertSessionHasErrors('email');

        DB::connection('pgsql_owner')->table('email_changes')->update(['expires_at' => now()->subMinute()]);
        expect($this->user->refresh()->email)->toBe('proprio@example.com');
    });

    it('n\'écrase pas une adresse devenue prise entre-temps', function (): void {
        Notification::fake();
        $tokens = [];
        $this->signedIn($this->user, $this->org)->post('/securite/email', ['current_password' => CURRENT_PASSWORD, 'email' => 'nouvelle@example.com']);
        Notification::assertSentOnDemand(ConfirmNewEmailNotification::class, function ($notification) use (&$tokens) {
            $tokens[] = (new ReflectionProperty($notification, 'token'))->getValue($notification);

            return true;
        });
        $this->activatedAccount(email: 'nouvelle@example.com', role: 'practitioner');

        $this->get(URL::temporarySignedRoute('security.email.confirm', now()->addHour(), ['token' => $tokens[0]]))->assertSessionHasErrors('email');

        expect($this->user->refresh()->email)->toBe('proprio@example.com');
    });
});

describe('téléphone', function (): void {
    beforeEach(function (): void {
        $this->sender = new FakePhoneCodeSender;
        app()->instance(PhoneCodeSender::class, $this->sender);
    });

    it('envoie un code au NOUVEAU numéro, sans rien changer avant la confirmation', function (): void {
        $this->user->forceFill(['phone_e164' => '+32470111111', 'phone_verified_at' => now()])->save();

        $this->signedIn($this->user, $this->org)->post('/securite/telephone', ['current_password' => CURRENT_PASSWORD, 'phone' => '+32 470 22 22 22'])->assertSessionHasNoErrors();

        expect($this->sender->lastCode())->toMatch('/^\d{6}$/')->and($this->user->refresh()->phone_e164)->toBe('+32470111111');
        $this->get('/securite')->assertInertia(fn (Assert $page) => $page->where('pendingPhone', '+32470222222'));
    });

    it('change le numéro avec le bon code', function (): void {
        $this->signedIn($this->user, $this->org)->post('/securite/telephone', ['current_password' => CURRENT_PASSWORD, 'phone' => '+32 470 22 22 22']);

        $this->post('/securite/telephone/confirmer', ['code' => $this->sender->lastCode()])->assertSessionHasNoErrors();

        expect($this->user->refresh()->phone_e164)->toBe('+32470222222')->and($this->user->phone_verified_at)->not->toBeNull();
    });

    it('refuse un mauvais code et bloque après 5 essais', function (): void {
        $this->signedIn($this->user, $this->org)->post('/securite/telephone', ['current_password' => CURRENT_PASSWORD, 'phone' => '+32 470 22 22 22']);
        $wrong = $this->sender->lastCode() === '000000' ? '111111' : '000000';

        foreach (range(1, 5) as $_) {
            $this->post('/securite/telephone/confirmer', ['code' => $wrong])->assertSessionHasErrors('code');
        }

        $this->post('/securite/telephone/confirmer', ['code' => $this->sender->lastCode()])->assertSessionHasErrors('code');
        expect($this->user->refresh()->phone_e164)->not->toBe('+32470222222');
    });

    it('exige le mot de passe et un numéro valide', function (): void {
        $this->signedIn($this->user, $this->org);

        $this->post('/securite/telephone', ['current_password' => 'faux', 'phone' => '+32 470 22 22 22'])->assertSessionHasErrors('current_password');
        $this->post('/securite/telephone', ['current_password' => CURRENT_PASSWORD, 'phone' => '12'])->assertSessionHasErrors('phone');
    });
});
