<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Modules\Admin\Models\PlatformAdmin;

/**
 * Garde-fou d'une action sensible du Super Admin (docs/02 §3.3). L'action est toujours tracée dans l'audit ; le motif est facultatif.
 *
 * Décisions du fondateur : ni mot de passe ni code 2FA par action (demandés à la connexion seulement), ni saisie du nom de
 * l'organisation, ni motif obligatoire.
 */
final class SensitiveActionGuard
{
    /** Motif nettoyé, ou `null` s'il est vide. */
    public function authorize(PlatformAdmin $admin, ?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }
}
