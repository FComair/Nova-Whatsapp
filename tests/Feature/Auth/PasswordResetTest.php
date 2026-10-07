<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\PasswordChangedNotification;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Flowchart 2A : mot de passe oublié (lien 60 min, usage unique, même réponse si l'e-mail est inconnu).
 */
beforeEach(function (): void {
    ['user' => $this->user, 'org' => $this->org] = $this->activatedAccount();
});

it('affiche la page de demande', function (): void {
    $this->get('/mot-de-passe-oublie')->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword'));
});

it('envoie le lien pour un compte existant', function (): void {
    Notification::fake();

    $this->post('/mot-de-passe-oublie', ['email' => 'proprio@example.com'])->assertSessionHas('status', __('ui.forgot.sent'));

    Notification::assertSentTo($this->user, ResetPasswordNotification::class);
});

it('donne exactement la même réponse pour un e-mail inconnu, sans rien envoyer', function (): void {
    Notification::fake();

    $this->post('/mot-de-passe-oublie', ['email' => 'inconnu@example.com'])->assertSessionHas('status', __('ui.forgot.sent'));

    Notification::assertNothingSent();
});

it('réinitialise le mot de passe, prévient par e-mail et déverrouille le compte', function (): void {
    Notification::fake();
    $this->asOwner(fn () => $this->user->forceFill(['locked_until' => now()->addMinutes(10), 'failed_login_count' => 3])->save());
    $token = Password::createToken($this->user);

    $this->post('/reinitialiser-mot-de-passe', [
        'token' => $token,
        'email' => 'proprio@example.com',
        'password' => 'Nouv3au-Phrase-Robuste!9',
        'password_confirmation' => 'Nouv3au-Phrase-Robuste!9',
    ])->assertRedirect('/connexion')->assertSessionHas('status', __('ui.reset.done'));

    $fresh = $this->asOwner(fn () => User::query()->find($this->user->id));
    expect(Hash::check('Nouv3au-Phrase-Robuste!9', $fresh->password))->toBeTrue()
        ->and($fresh->locked_until)->toBeNull()
        ->and($fresh->failed_login_count)->toBe(0);

    Notification::assertSentTo($this->user, PasswordChangedNotification::class);
    expect($this->asOwner(fn () => DB::table('audit_logs')->where('action', 'auth.password.reset')->count()))->toBe(1);

    // Le nouveau mot de passe fonctionne, l'ancien non.
    $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'Nouv3au-Phrase-Robuste!9'])->assertRedirect('/tableau-de-bord');
});

it('ferme les autres sessions : la session ouverte avant la réinitialisation n\'est plus valide', function (): void {
    Notification::fake();
    $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => 'password-de-test'])->assertRedirect('/tableau-de-bord');
    $this->get('/tableau-de-bord')->assertOk();

    $token = Password::createToken($this->user);
    $this->asOwner(fn () => Password::reset(
        ['email' => 'proprio@example.com', 'password' => 'Nouv3au-Phrase-Robuste!9', 'password_confirmation' => 'Nouv3au-Phrase-Robuste!9', 'token' => $token],
        fn (User $user, string $password) => $user->forceFill(['password' => $password])->save(),
    ));

    // Dans un test, l'application est partagée entre les requêtes : on vide le cache du guard pour simuler une nouvelle requête HTTP.
    auth()->forgetGuards();

    // Même session, mais l'empreinte du mot de passe a changé : AuthenticateSession la rejette.
    $this->get('/tableau-de-bord')->assertRedirect('/connexion');
});

it('n\'autorise qu\'un seul usage du lien', function (): void {
    Notification::fake();
    $token = Password::createToken($this->user);
    $payload = fn (string $password) => [
        'token' => $token, 'email' => 'proprio@example.com', 'password' => $password, 'password_confirmation' => $password,
    ];

    $this->post('/reinitialiser-mot-de-passe', $payload('Premier-Mdp-Solide-1!'))->assertRedirect('/connexion');
    $this->post('/reinitialiser-mot-de-passe', $payload('Second-Mdp-Solide-2!'))->assertSessionHasErrors(['email' => 'Ce lien a expiré.']);

    expect(Hash::check('Premier-Mdp-Solide-1!', $this->asOwner(fn () => User::query()->find($this->user->id))->password))->toBeTrue();
});

it('rejette le lien après 60 minutes', function (): void {
    $token = Password::createToken($this->user);
    $this->travel(61)->minutes();

    $this->post('/reinitialiser-mot-de-passe', [
        'token' => $token, 'email' => 'proprio@example.com',
        'password' => 'Nouv3au-Phrase-Robuste!9', 'password_confirmation' => 'Nouv3au-Phrase-Robuste!9',
    ])->assertSessionHasErrors(['email' => 'Ce lien a expiré.']);
});

it('rejette un jeton invalide', function (): void {
    $this->post('/reinitialiser-mot-de-passe', [
        'token' => 'jeton-bidon', 'email' => 'proprio@example.com',
        'password' => 'Nouv3au-Phrase-Robuste!9', 'password_confirmation' => 'Nouv3au-Phrase-Robuste!9',
    ])->assertSessionHasErrors('email');
});

it('refuse un mot de passe faible ou non confirmé', function (array $override): void {
    $token = Password::createToken($this->user);

    $this->post('/reinitialiser-mot-de-passe', array_merge([
        'token' => $token, 'email' => 'proprio@example.com',
        'password' => 'Nouv3au-Phrase-Robuste!9', 'password_confirmation' => 'Nouv3au-Phrase-Robuste!9',
    ], $override))->assertSessionHasErrors('password');
})->with([
    'trop court' => [['password' => 'court1', 'password_confirmation' => 'court1']],
    'sans chiffre' => [['password' => 'uniquement-des-lettres-longues', 'password_confirmation' => 'uniquement-des-lettres-longues']],
    'confirmation différente' => [['password_confirmation' => 'autre-chose-que-le-mot-de-passe-9']],
]);

it('affiche la page de réinitialisation avec le jeton', function (): void {
    $this->get('/reinitialiser-mot-de-passe/abc123?email=proprio@example.com')
        ->assertInertia(fn (Assert $page) => $page->component('Auth/ResetPassword')->where('token', 'abc123')->where('email', 'proprio@example.com'));
});

it('envoie l\'e-mail dans la langue du compte', function (string $locale, string $subject): void {
    $this->asOwner(fn () => $this->user->forceFill(['locale' => $locale])->save());

    $mail = (new ResetPasswordNotification('tok'))->toMail($this->asOwner(fn () => User::query()->find($this->user->id)));
    app()->setLocale($locale);
    $mail = (new ResetPasswordNotification('tok'))->toMail($this->asOwner(fn () => User::query()->find($this->user->id)));

    expect($mail->subject)->toBe($subject);
})->with([
    ['fr', 'Réinitialisation de votre mot de passe'],
    ['nl', 'Uw wachtwoord opnieuw instellen'],
    ['en', 'Reset your password'],
]);
