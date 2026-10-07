<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

/**
 * Double authentification du Super Admin (docs/02 §3, règle non négociable du projet : 2FA obligatoire).
 *
 * Elle peut être désactivée UNIQUEMENT en développement local, pour les essais (`NOVA_ADMIN_REQUIRE_MFA=false`). Hors de l'environnement
 * `local` (production, préproduction, tests…), la variable est IGNORÉE : la double authentification reste exigée, quelle que soit
 * la configuration. Le Super Admin se connecte alors avec son mot de passe seul, et ses actions sensibles ne demandent plus de code.
 */
final class AdminMfa
{
    public static function required(): bool
    {
        return ! (app()->environment('local') && config('nova.security.admin_mfa_required') === false);
    }
}
