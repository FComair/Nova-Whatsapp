<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Helpers de migration pour la Row-Level Security (docs/01-schema-base-de-donnees.md §0).
 *
 * Principe : la RLS est la vraie barrière d'isolation entre clients, pas la couche applicative.
 * - `nova_app`      : rôle applicatif client, ne voit que les lignes de l'organisation courante.
 * - `nova_platform` : rôle Super Admin, lecture transverse (les écritures sont accordées table par table).
 * Sans contexte d'organisation (`app.current_org` vide), aucune ligne n'est visible : échec fermé.
 */
final class Rls
{
    public const APP_ROLE = 'nova_app';

    public const PLATFORM_ROLE = 'nova_platform';

    /**
     * Isole une table portant une colonne d'organisation (par défaut `org_id`).
     */
    public static function enableTenantTable(string $table, string $column = 'org_id'): void
    {
        self::enable($table);

        DB::statement(sprintf(
            'CREATE POLICY tenant_isolation ON %s FOR ALL TO %s USING (%s = app_current_org()) WITH CHECK (%s = app_current_org())',
            $table, self::APP_ROLE, $column, $column,
        ));

        self::grantPlatformRead($table);
    }

    /**
     * Active la RLS sans politique : à compléter par des politiques explicites.
     */
    public static function enable(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        // FORCE : la RLS s'applique aussi au propriétaire de la table (sauf superuser / BYPASSRLS).
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Lecture transverse pour le Super Admin.
     */
    public static function grantPlatformRead(string $table): void
    {
        DB::statement(sprintf('CREATE POLICY platform_read ON %s FOR SELECT TO %s USING (true)', $table, self::PLATFORM_ROLE));
        DB::statement(sprintf('GRANT SELECT ON %s TO %s', $table, self::PLATFORM_ROLE));
    }
}
