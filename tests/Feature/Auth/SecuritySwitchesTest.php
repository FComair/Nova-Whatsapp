<?php

declare(strict_types=1);

use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Admin\Support\AdminMfa;
use App\Modules\Identity\Services\TwoFactor;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Interrupteurs des Paramètres globaux (décision du fondateur, 2026-10-15) : vérification de l'e-mail, 2FA des clients, 2FA du Super Admin.
 * Exigés par défaut ; coupés uniquement par le Super Admin, faute de serveur d'e-mail.
 */
const SWITCH_PASSWORD = 'password-de-test';

$set = function (string $key, int $value): void {
    DB::connection('pgsql_owner')->table('platform_settings')->where('key', $key)->update(['value' => (string) $value]);
};

afterEach(function () use ($set): void {
    foreach (PlatformSettings::FLAGS as $key) {
        $set($key, 1);
    }
});

it('exige tout par défaut', function (): void {
    foreach (PlatformSettings::FLAGS as $key) {
        expect(app(PlatformSettings::class)->enabled($key))->toBeTrue();
    }
});

it('reste exigé quand le réglage est absent', function (): void {
    DB::connection('pgsql_owner')->table('platform_settings')->where('key', 'client_two_factor_required')->delete();

    expect(app(PlatformSettings::class)->enabled('client_two_factor_required'))->toBeTrue();

    DB::connection('pgsql_owner')->table('platform_settings')->insert(['key' => 'client_two_factor_required', 'value' => '1', 'updated_at' => now()]);
});

it('ne contraint plus les clients à activer la 2FA quand l\'interrupteur est coupé', function () use ($set): void {
    config(['rendivoo.security.two_factor_required' => true]);
    expect(app(TwoFactor::class)->isRequiredFor(OrgMember::ROLE_OWNER))->toBeTrue();

    $set('client_two_factor_required', 0);

    expect(app(TwoFactor::class)->isRequiredFor(OrgMember::ROLE_OWNER))->toBeFalse()
        ->and(app(TwoFactor::class)->enabled())->toBeFalse();
});

it('coupe la 2FA du Super Admin partout, y compris hors développement local', function () use ($set): void {
    $this->app['env'] = 'production';
    expect(AdminMfa::required())->toBeTrue();

    $set('admin_two_factor_required', 0);

    expect(AdminMfa::required())->toBeFalse();
});

it('laisse un compte dont le téléphone n\'est pas vérifié se connecter quand la vérification est coupée', function () use ($set): void {
    ['user' => $user] = $this->activatedAccount();
    $this->asOwner(fn () => $user->forceFill(['phone_verified_at' => null])->save());

    $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => SWITCH_PASSWORD])->assertRedirect('/verification-telephone');
    $this->assertGuest();

    $set('phone_verification_required', 0);

    $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => SWITCH_PASSWORD])->assertRedirect();
    $this->assertAuthenticated();
});

it('laisse un compte dont l\'e-mail n\'est pas vérifié se connecter quand la vérification est coupée', function () use ($set): void {
    ['user' => $user] = $this->activatedAccount();
    $this->asOwner(fn () => $user->forceFill(['email_verified_at' => null])->save());

    $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => SWITCH_PASSWORD])->assertRedirect('/verification-email');
    $this->assertGuest();

    $set('email_verification_required', 0);

    $this->post('/connexion', ['email' => 'proprio@example.com', 'password' => SWITCH_PASSWORD])->assertRedirect();
    $this->assertAuthenticated();
});
