<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Models\PlatformAdmin;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Qui peut faire quoi dans le panel (docs/38). Le catalogue est dans `AdminPermissions` ; ici on le confronte au compte.
 *
 *  - Compte inactif (invité, suspendu, retiré) : rien.
 *  - Agent de support : les permissions « tickets » (le détail, dont le périmètre client, reste réglé par `SupportAccess` et `SupportPermissions`)
 *    et les rapports du support ; éditeur de contenu : les FAQ. Rien d'autre : leurs écrans restreints existent déjà.
 *  - Administrateur : les permissions de son rôle (liste choisie pour `custom`). Sans rôle enregistré, un compte historique est Super Admin.
 *
 * Les écrans masquent ce qui est interdit ; les services appellent `authorize()` : une action interdite échoue même si quelqu'un appelle le
 * service sans passer par l'écran.
 */
final class AdminAccess
{
    /**
     * @param  PlatformAdmin|string|null  $admin  le compte, son identifiant, ou le compte connecté si absent
     */
    public static function can(string $permission, PlatformAdmin|string|null $admin = null): bool
    {
        $admin = self::resolve($admin);

        return $admin !== null && $admin->isActive() && in_array($permission, self::permissionsOf($admin), true);
    }

    /**
     * @throws AuthorizationException
     */
    public static function authorize(PlatformAdmin|string|null $admin, string $permission): void
    {
        if (! self::can($permission, $admin)) {
            throw new AuthorizationException(__('admins.errors.forbidden'));
        }
    }

    /**
     * Permissions effectives du compte.
     *
     * @return list<string>
     */
    public static function permissionsOf(PlatformAdmin $admin): array
    {
        if ($admin->isSupportAgent()) {
            return ['tickets.view', 'tickets.create', 'tickets.reply', 'tickets.assign', 'tickets.resolve', 'tickets.delete', 'reports.view'];
        }

        if ($admin->isContentEditor()) {
            return ['faq.view', 'faq.create', 'faq.edit', 'faq.delete'];
        }

        return AdminPermissions::forRole($admin->adminRole(), $admin->permissions);
    }

    private static function resolve(PlatformAdmin|string|null $admin): ?PlatformAdmin
    {
        return match (true) {
            $admin instanceof PlatformAdmin => $admin,
            // Un identifiant qui n'est pas celui d'un compte (ou pas un UUID) n'ouvre aucun droit.
            is_string($admin) => Str::isUuid($admin) ? PlatformAdmin::query()->find($admin) : null,
            default => SupportAccess::admin(),
        };
    }
}
