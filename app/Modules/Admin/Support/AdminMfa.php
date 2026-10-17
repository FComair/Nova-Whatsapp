<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Services\PlatformSettings;

/**
 * Double authentification du Super Admin (docs/02 §3, règle non négociable du projet : 2FA obligatoire).
 *
 * Deux façons de la couper : en développement local, la variable `NOVA_ADMIN_REQUIRE_MFA=false` (ignorée ailleurs) ; ou l'interrupteur
 * « 2FA du Super Admin » des Paramètres globaux, valable partout (décision du fondateur, 2026-10-15, faute de serveur d'e-mail).
 * Le Super Admin se connecte alors avec son mot de passe seul, et ses actions sensibles ne demandent plus de code.
 */
final class AdminMfa
{
    public static function required(): bool
    {
        if (app()->environment('local') && config('rendivoo.security.admin_mfa_required') === false) {
            return false;
        }

        return app(PlatformSettings::class)->enabled('admin_two_factor_required');
    }
}
