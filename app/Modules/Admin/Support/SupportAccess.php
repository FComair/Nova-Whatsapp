<?php

declare(strict_types=1);

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Qui voit et fait quoi dans le Super Admin pour le support et le contenu d'aide (docs/29 §5, docs/30 §7).
 *
 *  - Super Admin (compte sans rôle restreint) : tout ; tous les tickets, rapports, export, FAQ, réglages.
 *  - Agent de support (`support_role = agent`) : ses tickets et les tickets non assignés ; rien d'autre (ni comptes, ni facturation, ni FAQ).
 *  - Éditeur de contenu (`support_role = editor`) : les FAQ et leurs catégories seulement ; aucun ticket.
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

    public static function isEditor(?PlatformAdmin $admin = null): bool
    {
        $admin ??= self::admin();

        return $admin !== null && $admin->isContentEditor();
    }

    /** Compte à rôle restreint (agent ou éditeur) : n'accède pas aux écrans réservés au Super Admin. */
    public static function isRestricted(?PlatformAdmin $admin = null): bool
    {
        $admin ??= self::admin();

        return $admin !== null && $admin->support_role !== null;
    }

    /** Les tickets sont réservés au Super Admin et aux agents. */
    public static function canUseTickets(?PlatformAdmin $admin = null): bool
    {
        $admin ??= self::admin();

        return $admin !== null && ! $admin->isContentEditor();
    }

    /** Les FAQ sont réservées au Super Admin et aux éditeurs de contenu. */
    public static function canManageFaq(?PlatformAdmin $admin = null): bool
    {
        $admin ??= self::admin();

        return $admin !== null && ! $admin->isSupportAgent();
    }

    /**
     * Limite une requête sur `support_tickets` aux tickets que la personne peut voir : l'agent, les siens et les non assignés ; l'éditeur
     * de contenu, aucun.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, PlatformAdmin $admin, string $table = 'support_tickets'): Builder
    {
        if ($admin->isContentEditor()) {
            return $query->whereRaw('1 = 0');
        }

        if (! $admin->isSupportAgent()) {
            return $query;
        }

        $query->where(fn (Builder $inner) => $inner->whereNull("{$table}.assigned_admin_id")->orWhere("{$table}.assigned_admin_id", $admin->id));

        // Périmètre client : un agent limité à certains clients ne voit pas les tickets des autres, assignés ou non.
        return $query->whereRaw("(not exists (select 1 from platform_admin_client_scopes s0 where s0.admin_id = ?) or exists (select 1 from platform_admin_client_scopes s1 where s1.admin_id = ? and s1.org_id = {$table}.org_id))", [$admin->id, $admin->id]);
    }

    /** Le client est-il dans le périmètre de l'agent ? Sans périmètre enregistré, tous les clients le sont. */
    public static function inScope(PlatformAdmin $admin, string $orgId): bool
    {
        if (! $admin->isSupportAgent()) {
            return true;
        }

        $scopes = DB::connection('pgsql_platform')->table('platform_admin_client_scopes')->where('admin_id', $admin->id);

        return ! (clone $scopes)->exists() || (clone $scopes)->where('org_id', $orgId)->exists();
    }

    public static function canExport(PlatformAdmin $admin): bool
    {
        return $admin->support_role === null && AdminAccess::can('reports.export', $admin);
    }

    /**
     * Ce que `$admin` peut choisir dans une liste d'assignation : tous les comptes actifs avec la permission « assigner à d'autres »,
     * sinon lui seul (prendre un ticket).
     *
     * @return array<string, string>
     */
    public static function assignableBy(PlatformAdmin $admin, ?string $orgId = null): array
    {
        $all = self::assignees($orgId);

        return SupportPermissions::can($admin, SupportPermissions::ASSIGN_OTHERS) ? $all : array_intersect_key($all, [$admin->id => true]);
    }

    /**
     * Tous les comptes qui ont pu travailler sur un ticket (y compris suspendus), pour AFFICHER ou filtrer un assigné : id => nom.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        $options = [];

        foreach (DB::connection('pgsql_platform')->table('platform_admins')->where('status', '!=', PlatformAdmin::STATUS_REMOVED)->orderBy('email')->get(['id', 'email', 'name']) as $row) {
            $options[(string) $row->id] = $row->name !== null && $row->name !== '' ? "{$row->name} ({$row->email})" : (string) $row->email;
        }

        return $options;
    }

    /**
     * Comptes à qui l'on peut confier un ticket : id => nom (jamais un éditeur de contenu, ni un compte invité, suspendu ou retiré). Avec
     * `$orgId`, seuls les agents dont le périmètre couvre ce client.
     *
     * @return array<string, string>
     */
    public static function assignees(?string $orgId = null): array
    {
        $rows = DB::connection('pgsql_platform')->table('platform_admins')
            ->where('status', PlatformAdmin::STATUS_ACTIVE)
            ->where(fn ($q) => $q->whereNull('support_role')->orWhere('support_role', PlatformAdmin::SUPPORT_AGENT))
            ->orderBy('email')->get(['id', 'email', 'name', 'support_role']);

        $options = [];

        foreach ($rows as $row) {
            if ($orgId !== null && $row->support_role !== null && ! self::inScope((new PlatformAdmin)->forceFill(['id' => $row->id, 'support_role' => $row->support_role]), $orgId)) {
                continue;
            }

            $options[(string) $row->id] = $row->name !== null && $row->name !== '' ? "{$row->name} ({$row->email})" : (string) $row->email;
        }

        return $options;
    }
}
