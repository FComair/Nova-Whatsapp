<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Flowchart 1A : inscription, création de l'organisation d'essai et du compte propriétaire, erreur E1.
 */
it('affiche la page d\'inscription avec les secteurs proposés', function (): void {
    $this->get('/inscription')->assertInertia(fn (Assert $page) => $page
        ->component('Auth/Register')
        ->has('sectors', count(config('nova.sectors')))
        ->where('plan', 'trial'));
});

it('crée l\'organisation d\'essai, le site par défaut, le propriétaire et les consentements', function (): void {
    Notification::fake();

    $this->post('/inscription', $this->validRegistration())->assertRedirect('/verification-email');

    $owner = fn (callable $cb) => $this->asOwner($cb);
    $user = $owner(fn () => User::query()->where('email', 'nouveau@example.com')->firstOrFail());

    expect($user->phone_e164)->toBe('+32470123456')
        ->and($user->locale)->toBe('fr')
        ->and($user->hasVerifiedEmail())->toBeFalse();

    $org = $owner(fn () => DB::table('organizations')->first());
    expect($org->status)->toBe('trial')
        ->and($org->sector)->toBe('hairdressing')
        ->and($org->signup_plan)->toBe('trial');

    expect($owner(fn () => DB::table('locations')->where('org_id', $org->id)->where('is_default', true)->value('name')))->toBe('Site principal')
        ->and($owner(fn () => DB::table('org_members')->where('org_id', $org->id)->where('user_id', $user->id)->value('role')))->toBe('owner')
        ->and($owner(fn () => DB::table('user_consents')->where('user_id', $user->id)->pluck('type')->sort()->values()->all()))->toBe(['dpa', 'privacy', 'terms'])
        ->and($owner(fn () => DB::table('audit_logs')->where('action', 'account.registered')->where('org_id', $org->id)->count()))->toBe(1);

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('n\'ouvre pas de session avant la vérification de l\'e-mail et du téléphone', function (): void {
    Notification::fake();

    $this->post('/inscription', $this->validRegistration());

    $this->assertGuest();
    $this->get('/tableau-de-bord')->assertRedirect('/connexion');
});

it('enregistre la langue de l\'interface sur le compte et l\'organisation', function (): void {
    Notification::fake();

    $this->withSession(['locale' => 'nl'])->post('/inscription', $this->validRegistration());

    $user = $this->asOwner(fn () => User::query()->firstOrFail());
    expect($user->locale)->toBe('nl')
        ->and($this->asOwner(fn () => DB::table('locations')->value('name')))->toBe('Hoofdvestiging')
        ->and($this->asOwner(fn () => DB::table('organizations')->value('locale')))->toBe('nl');
});

it('refuse un e-mail déjà inscrit avec le message E1, sans tenir compte de la casse', function (): void {
    $this->activatedAccount(email: 'deja@example.com');

    $this->post('/inscription', $this->validRegistration(['email' => 'DEJA@Example.com']))
        ->assertSessionHasErrors(['email' => 'Cet e-mail est déjà associé à un compte.']);

    expect($this->asOwner(fn () => DB::table('users')->count()))->toBe(1);
});

it('rend le message E1 dans la langue de l\'interface', function (string $locale, string $message): void {
    $this->activatedAccount(email: 'deja@example.com');

    $this->withSession(['locale' => $locale])->post('/inscription', $this->validRegistration(['email' => 'deja@example.com']))
        ->assertSessionHasErrors(['email' => $message]);
})->with([
    ['fr', 'Cet e-mail est déjà associé à un compte.'],
    ['nl', 'Dit e-mailadres is al aan een account gekoppeld.'],
    ['en', 'This email is already linked to an account.'],
]);

it('refuse l\'inscription si un document juridique n\'est pas accepté', function (string $field): void {
    $this->post('/inscription', $this->validRegistration([$field => false]))->assertSessionHasErrors($field);

    expect($this->asOwner(fn () => DB::table('users')->count()))->toBe(0);
})->with(['accept_terms', 'accept_privacy', 'accept_dpa']);

it('refuse les données invalides', function (array $override, string $field): void {
    $this->post('/inscription', $this->validRegistration($override))->assertSessionHasErrors($field);

    expect($this->asOwner(fn () => DB::table('organizations')->count()))->toBe(0);
})->with([
    'mot de passe trop court' => [['password' => 'court1'], 'password'],
    'mot de passe sans chiffre' => [['password' => 'Uniquement-Des-Lettres-Longues!'], 'password'],
    'mot de passe sans majuscule' => [['password' => 'sol1de-phrase-pour-test!'], 'password'],
    'mot de passe sans caractere special' => [['password' => 'Sol1dePhrasePourTest'], 'password'],
    'mot de passe evident decore' => [['password' => 'P@ssw0rd-Nova-2026!'], 'password'],
    'mot de passe tire de l e-mail' => [['email' => 'jeanne.dupont@example.com', 'password' => 'Jeanne.Dupont-2026!'], 'password'],
    'e-mail invalide' => [['email' => 'pas-un-email'], 'email'],
    'téléphone invalide' => [['phone' => '12345'], 'phone'],
    'secteur inconnu' => [['sector' => 'pirate'], 'sector'],
    'plan inconnu' => [['plan' => 'gratuit-a-vie'], 'plan'],
]);

it('ne touche pas aux données d\'une autre organisation', function (): void {
    Notification::fake();
    ['org' => $existing] = $this->activatedAccount();

    $this->post('/inscription', $this->validRegistration());

    expect($this->asOwner(fn () => DB::table('organizations')->count()))->toBe(2)
        ->and($this->asOwner(fn () => DB::table('locations')->where('org_id', $existing->id)->count()))->toBe(1)
        ->and($this->asOwner(fn () => DB::table('org_members')->where('org_id', $existing->id)->count()))->toBe(1);
});

it('libère le contexte d\'organisation après l\'inscription', function (): void {
    Notification::fake();

    $this->post('/inscription', $this->validRegistration());

    expect(app(TenantContext::class)->current())->toBeNull();
});
