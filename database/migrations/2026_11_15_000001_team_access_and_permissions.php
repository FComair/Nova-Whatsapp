<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Équipe et accès d'un établissement (docs/34).
 *
 *  - `org_members` : nom d'affichage, téléphone, préréglage (praticien / assistant / personnalisé), écarts de permissions par rapport au rôle de
 *    base (jsonb : capacité => vrai / faux), notes, dates de suspension et de retrait ; nouveau statut « removed » (retrait doux : l'historique
 *    reste attribué à la personne, mais elle n'entre plus).
 *  - `auth_memberships()` renvoie aussi les écarts de permissions : ils sont posés avec le rôle à chaque requête (seuls les membres « active » passent).
 *  - `invitations` : un assistant n'a pas de fiche praticien (practitioner_id facultatif) ; rôle praticien ou réceptionniste ; nom, téléphone,
 *    préréglage, permissions et notes voyagent avec l'invitation jusqu'à son acceptation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('org_members', function (Blueprint $table): void {
            $table->string('name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('preset', 12)->nullable();
            $table->jsonb('permissions')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('invited_at')->nullable();
            $table->uuid('invited_by')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('removed_at')->nullable();
        });
        DB::statement('ALTER TABLE org_members DROP CONSTRAINT org_members_status_check');
        DB::statement("ALTER TABLE org_members ADD CONSTRAINT org_members_status_check CHECK (status IN ('active','invited','disabled','removed'))");
        DB::statement("ALTER TABLE org_members ADD CONSTRAINT org_members_preset_check CHECK (preset IS NULL OR preset IN ('practitioner','assistant','custom'))");

        DB::statement('DROP FUNCTION IF EXISTS auth_memberships(uuid)');
        DB::statement(<<<'SQL'
            CREATE FUNCTION auth_memberships(p_user_id uuid)
            RETURNS TABLE (org_id uuid, role varchar, status varchar, permissions jsonb)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT m.org_id, m.role::varchar, m.status::varchar, m.permissions
                FROM org_members m
                WHERE m.user_id = p_user_id AND m.status = 'active'
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION auth_memberships(uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION auth_memberships(uuid) TO nova_app');

        Schema::table('invitations', function (Blueprint $table): void {
            $table->string('name', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('preset', 12)->nullable();
            $table->jsonb('permissions')->nullable();
            $table->text('notes')->nullable();
        });
        DB::statement('ALTER TABLE invitations ALTER COLUMN practitioner_id DROP NOT NULL');
        DB::statement('ALTER TABLE invitations DROP CONSTRAINT invitations_role_check');
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('practitioner','receptionist'))");
        // Une seule invitation en attente par adresse et par établissement (les praticiens gardent en plus leur propre garde-fou).
        DB::statement('CREATE UNIQUE INDEX invitations_one_pending_per_email ON invitations (org_id, email) WHERE used_at IS NULL AND revoked_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invitations_one_pending_per_email');
        DB::statement('DELETE FROM invitations WHERE practitioner_id IS NULL');
        DB::statement('ALTER TABLE invitations DROP CONSTRAINT invitations_role_check');
        DB::statement("DELETE FROM invitations WHERE role <> 'practitioner'");
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('practitioner'))");
        DB::statement('ALTER TABLE invitations ALTER COLUMN practitioner_id SET NOT NULL');
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropColumn(['name', 'phone', 'preset', 'permissions', 'notes']);
        });

        DB::statement('DROP FUNCTION IF EXISTS auth_memberships(uuid)');
        DB::statement(<<<'SQL'
            CREATE FUNCTION auth_memberships(p_user_id uuid)
            RETURNS TABLE (org_id uuid, role varchar, status varchar)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT m.org_id, m.role::varchar, m.status::varchar
                FROM org_members m
                WHERE m.user_id = p_user_id AND m.status = 'active'
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION auth_memberships(uuid) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION auth_memberships(uuid) TO nova_app');

        DB::statement("DELETE FROM org_members WHERE status = 'removed'");
        DB::statement('ALTER TABLE org_members DROP CONSTRAINT org_members_preset_check');
        DB::statement('ALTER TABLE org_members DROP CONSTRAINT org_members_status_check');
        DB::statement("ALTER TABLE org_members ADD CONSTRAINT org_members_status_check CHECK (status IN ('active','invited','disabled'))");
        Schema::table('org_members', function (Blueprint $table): void {
            $table->dropColumn(['name', 'phone', 'preset', 'permissions', 'notes', 'invited_at', 'invited_by', 'suspended_at', 'removed_at']);
        });
    }
};
