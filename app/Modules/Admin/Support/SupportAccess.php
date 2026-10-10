<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Qui voit et fait quoi dans le support (docs/29 §5).
 *
 *  - Super Admin (compte sans rôle de support) : tous les tickets de tous les comptes, rapports complets, export, réglages.
 *  - Agent de support : ses tickets et les tickets non assignés ; rien d'autre dans le panel (ni comptes, ni facturation, ni réglages) ;
 *    ses chiffres de rapport sont limités à ces tickets ; pas d'export.
 *  - Client : uniquement ses tickets, jamais les notes internes (règles de la base, voir docs/22 §9).
 */
final class SupportAccess
{
    public static function admin(): ?PlatformAdmin
    {
        $user = auth('platform')->user();

        return $user instanceof PlatformAdmin ? $user : null;
    }

    public static function isAgent(?PlatformAdmin $admin = null): bool
    {
        $admin ??= self::admin();

        return $admin !== null && $admin->isSupportAgent();
    }

    /**
     * Limite une requête sur `support_tickets` aux tickets que l'agent peut voir.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, PlatformAdmin $admin, string $table = 'support_tickets'): Builder
    {
        if (! $admin->isSupportAgent()) {
            return $query;
        }

        return $query->where(fn (Builder $inner) => $inner->whereNull("{$table}.assigned_admin_id")->orWhere("{$table}.assigned_admin_id", $admin->id));
    }

    public static function canExport(PlatformAdmin $admin): bool
    {
        return ! $admin->isSupportAgent();
    }

    /**
     * Comptes à qui l'on peut confier un ticket : id => e-mail.
     *
     * @return array<string, string>
     */
    public static function assignees(): array
    {
        return DB::connection('pgsql_platform')->table('platform_admins')->orderBy('email')->pluck('email', 'id')->all();
    }
}
