<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        // Organisation courante de la requête, posée par le middleware via set_config('app.current_org', ...).
        // Vide ou absente => NULL => aucune ligne visible (échec fermé).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_org() RETURNS uuid
            LANGUAGE sql STABLE
            AS $$ SELECT NULLIF(current_setting('app.current_org', true), '')::uuid $$
        SQL);

        // Empêche toute modification des tables append-only (journaux d'audit).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION forbid_modification() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'La table % est en ajout seul : % interdit', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS forbid_modification()');
        DB::statement('DROP FUNCTION IF EXISTS app_current_org()');
    }
};
