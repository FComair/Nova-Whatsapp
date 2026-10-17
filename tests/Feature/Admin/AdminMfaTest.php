<?php

declare(strict_types=1);

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Support\AdminMfa;

/**
 * La double authentification du Super Admin ne peut être désactivée qu'en développement local : partout ailleurs, la variable est ignorée.
 */
const MFA_PASSWORD = 'mot-de-passe-du-fondateur-2026!';

beforeEach(function (): void {
    $this->admin = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => MFA_PASSWORD]);
    $this->setUp2fa = function (string $environment, bool $flag): void {
        $this->app['env'] = $environment;
        config(['rendivoo.security.admin_mfa_required' => $flag]);
    };
});

it('n\'accepte la désactivation qu\'en développement local', function (string $environment, bool $flag, bool $expected): void {
    ($this->setUp2fa)($environment, $flag);

    expect(AdminMfa::required())->toBe($expected);
})->with([
    'local, désactivée' => ['local', false, false],
    'local, activée' => ['local', true, true],
    'production, désactivée par erreur' => ['production', false, true],
    'préproduction, désactivée par erreur' => ['staging', false, true],
    'tests, désactivée' => ['testing', false, true],
]);
