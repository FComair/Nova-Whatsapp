<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

/**
 * Catalogue des permissions du panel Super Admin et des rôles prédéfinis (docs/38).
 *
 * Une permission est « module.action ». Elle ne figure ici QUE si une action réelle de l'application la contrôle : pas de case qui ne fait rien.
 * Volontairement absents : l'imitation d'un client (exclue par décision du fondateur, jamais d'impersonation), le remboursement, l'annulation et le
 * changement de formule (la facturation est simulée, aucune de ces actions n'existe), répondre à un avis ou le modérer (la liste des avis est en
 * lecture seule), l'export du journal d'audit (aucun export n'existe).
 *
 * Rôles : `super_admin` a tout (dont la gestion des administrateurs) ; `operations`, `support` et `read_only` sont des ensembles fixes ; `custom` prend
 * la liste choisie par le Super Admin. La gestion des administrateurs (`admins.*`) n'est jamais déléguée : elle reste au Super Admin.
 */
final class AdminPermissions
{
    public const SUPER_ADMIN = 'super_admin';

    public const OPERATIONS = 'operations';

    public const SUPPORT = 'support';

    public const READ_ONLY = 'read_only';

    public const CUSTOM = 'custom';

    /** @var list<string> */
    public const ROLES = [self::SUPER_ADMIN, self::OPERATIONS, self::SUPPORT, self::READ_ONLY, self::CUSTOM];

    /** Modules et actions que le Super Admin peut accorder, dans l'ordre d'affichage. */
    public const MODULES = [
        'dashboard' => ['view'],
        'clients' => ['view', 'edit', 'suspend', 'delete', 'export'],
        'subscriptions' => ['view', 'edit'],
        'tickets' => ['view', 'create', 'reply', 'assign', 'resolve', 'delete'],
        'support_agents' => ['view', 'create', 'edit', 'suspend', 'delete'],
        'ratings' => ['view'],
        'faq' => ['view', 'create', 'edit', 'delete'],
        'legal' => ['view', 'upload', 'replace', 'download_archived'],
        'settings' => ['view', 'edit'],
        'reports' => ['view', 'export'],
        'audit' => ['view'],
    ];

    /** Gestion des administrateurs : réservée au rôle Super Admin, jamais accordée à un autre rôle. */
    public const ADMINS = ['view', 'create', 'edit', 'suspend', 'delete'];

    /**
     * Toutes les permissions d'un module accordables.
     *
     * @return list<string>
     */
    public static function assignable(): array
    {
        $all = [];

        foreach (self::MODULES as $module => $actions) {
            foreach ($actions as $action) {
                $all[] = "{$module}.{$action}";
            }
        }

        return $all;
    }

    /**
     * Toutes les permissions, gestion des administrateurs comprise (ce que possède un Super Admin).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::assignable(), ...array_map(fn (string $action): string => "admins.{$action}", self::ADMINS)];
    }

    /**
     * Permissions d'un rôle. Pour `custom`, la liste choisie (filtrée sur le catalogue).
     *
     * @param  array<array-key, mixed>|null  $chosen
     * @return list<string>
     */
    public static function forRole(string $role, ?array $chosen = null): array
    {
        return match ($role) {
            self::SUPER_ADMIN => self::all(),
            self::OPERATIONS => ['dashboard.view', 'clients.view', 'clients.edit', 'clients.suspend', 'clients.export', 'subscriptions.view', 'subscriptions.edit', 'settings.view', 'settings.edit', 'reports.view', 'reports.export'],
            self::SUPPORT => ['dashboard.view', 'tickets.view', 'tickets.create', 'tickets.reply', 'tickets.assign', 'tickets.resolve', 'support_agents.view', 'support_agents.create', 'support_agents.edit', 'support_agents.suspend', 'ratings.view', 'faq.view', 'faq.create', 'faq.edit', 'reports.view'],
            self::READ_ONLY => array_values(array_filter(self::assignable(), fn (string $permission): bool => str_ends_with($permission, '.view'))),
            default => self::normalise($chosen ?? []),
        };
    }

    /**
     * Garde ce qui est au catalogue accordable (jamais `admins.*`), sans doublon, dans l'ordre du catalogue.
     *
     * @param  array<array-key, mixed>  $permissions  liste de clés `module.action`, ou tableau clé => booléen
     * @return list<string>
     */
    public static function normalise(array $permissions): array
    {
        $keys = array_is_list($permissions)
            ? array_map('strval', $permissions)
            : array_map('strval', array_keys(array_filter($permissions)));

        return array_values(array_intersect(self::assignable(), $keys));
    }

    public static function isRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }
}
