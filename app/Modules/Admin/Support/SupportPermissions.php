<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Models\PlatformAdmin;

/**
 * Permissions fines d'un agent de support (docs/32 §3). Le Super Admin les a toutes ; un éditeur de contenu n'en a aucune ;
 * pour un agent, chaque permission vaut sa valeur enregistrée (`platform_admins.permissions`) ou, à défaut, sa valeur par défaut.
 * Toujours vérifiées côté serveur (SupportConsole) : l'interface ne fait que masquer les actions refusées.
 */
final class SupportPermissions
{
    public const DELETE_TICKETS = 'delete_tickets';

    public const CHANGE_PRIORITY = 'change_priority';

    public const MANAGE_TAGS = 'manage_tags';

    public const REOPEN_TICKETS = 'reopen_tickets';

    public const ASSIGN_OTHERS = 'assign_others';

    /** Permission => valeur par défaut d'un agent. La suppression est refusée tant qu'un Super Admin ne l'accorde pas. */
    public const DEFAULTS = [
        self::CHANGE_PRIORITY => true,
        self::MANAGE_TAGS => true,
        self::REOPEN_TICKETS => true,
        self::ASSIGN_OTHERS => false,
        self::DELETE_TICKETS => false,
    ];

    public static function can(PlatformAdmin $admin, string $permission): bool
    {
        if ($admin->support_role === null) {
            return true;
        }

        if (! $admin->isSupportAgent() || ! array_key_exists($permission, self::DEFAULTS)) {
            return false;
        }

        return (bool) (($admin->permissions ?? [])[$permission] ?? self::DEFAULTS[$permission]);
    }

    /**
     * @param  array<string, mixed>  $given
     * @return array<string, bool>
     */
    public static function normalise(array $given): array
    {
        $clean = [];

        foreach (self::DEFAULTS as $permission => $default) {
            $clean[$permission] = (bool) ($given[$permission] ?? $default);
        }

        return $clean;
    }
}
