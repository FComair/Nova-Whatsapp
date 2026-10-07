<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Statut avant une suspension ou une suppression programmée : c'est celui que retrouve le compte à la levée.
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('previous_status', 20)->nullable();
        });

        // Paramètres globaux modifiables par le Super Admin (docs/01 : durées paramétrables, jamais en dur).
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->string('key', 60)->primary();
            $table->jsonb('value');
            $table->uuid('updated_by')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });

        // Les droits par défaut donnent tout à nova_app : on les retire, seul le Super Admin écrit ces paramètres.
        DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON platform_settings FROM nova_app');
        DB::statement('GRANT SELECT ON platform_settings TO nova_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE ON platform_settings TO nova_platform');

        // Décisions du fondateur (Q7, Q8) : suppression à J+30 ; pause gratuite de 90 jours ; suspension de 60 jours.
        // Seul le délai de suppression est exploité pour l'instant (les deux autres attendent leur règle de fin de période).
        DB::table('platform_settings')->insert([
            ['key' => 'deletion_delay_days', 'value' => '30', 'updated_at' => now()],
            ['key' => 'pause_max_days', 'value' => '90', 'updated_at' => now()],
            ['key' => 'suspension_max_days', 'value' => '60', 'updated_at' => now()],
        ]);

        // Le Super Admin ne lit pas la table des utilisateurs : pour prévenir le propriétaire d'une action sensible, une fonction
        // SECURITY DEFINER étroite ne renvoie que l'e-mail et la langue du propriétaire actif de l'organisation.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION org_owner_contact(p_org_id uuid)
            RETURNS TABLE (email citext, locale text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT u.email, u.locale::text
                FROM org_members m
                JOIN users u ON u.id = m.user_id
                WHERE m.org_id = p_org_id AND m.role = 'owner' AND m.status = 'active'
                LIMIT 1
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION org_owner_contact(uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION org_owner_contact(uuid) TO nova_platform');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS org_owner_contact(uuid)');
        Schema::dropIfExists('platform_settings');
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('previous_status');
        });
    }
};
