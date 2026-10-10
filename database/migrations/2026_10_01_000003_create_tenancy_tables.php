<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agrégat racine (docs/01 §1). `paused` ajouté par rapport à la spec (flowchart 4B).
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('sector', 50)->nullable();
            $table->string('country', 2)->default('BE');
            $table->string('timezone', 64)->default('Europe/Brussels');
            $table->string('locale', 2)->default('fr');
            $table->string('status', 30)->default('trial');
            $table->uuid('plan_id')->nullable(); // FK ajoutée avec la table `plans` (étape facturation)
            $table->jsonb('terminology')->nullable();
            $table->boolean('data_sensitivity')->default(false);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('pause_until')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestampTz('suspension_ends_at')->nullable();
            $table->jsonb('unavailable_message')->nullable(); // message « service indisponible » par langue
            $table->timestampTz('deletion_requested_at')->nullable();
            $table->timestampTz('deletion_scheduled_for')->nullable();
            $table->string('billing_name')->nullable();
            $table->string('billing_vat_number', 30)->nullable();
            $table->jsonb('billing_address')->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();
        });
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ('trial','active','past_due','suspended','paused','pending_deletion','deleted'))");
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_locale_check CHECK (locale IN ('fr','nl','en'))");

        Schema::create('locations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('name');
            $table->jsonb('address')->nullable();
            $table->string('timezone', 64)->default('Europe/Brussels');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('org_id');
        });
        // Un seul site par défaut par organisation.
        DB::statement('CREATE UNIQUE INDEX locations_one_default_per_org ON locations (org_id) WHERE is_default AND deleted_at IS NULL');

        Schema::create('org_members', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->string('role', 30);
            $table->string('status', 20)->default('active');
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['org_id', 'user_id']);
            $table->index('user_id');
        });
        DB::statement("ALTER TABLE org_members ADD CONSTRAINT org_members_role_check CHECK (role IN ('owner','site_manager','practitioner','receptionist'))");
        DB::statement("ALTER TABLE org_members ADD CONSTRAINT org_members_status_check CHECK (status IN ('active','invited','disabled'))");
        // Un seul propriétaire actif par organisation (flowchart 4C : seul le propriétaire peut supprimer).
        DB::statement("CREATE UNIQUE INDEX org_members_one_owner ON org_members (org_id) WHERE role = 'owner' AND status = 'active'");

        Schema::create('org_member_locations', function (Blueprint $table): void {
            $table->uuid('org_id');
            $table->uuid('member_id');
            $table->uuid('location_id');

            $table->primary(['member_id', 'location_id']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('org_members')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
        });

        // --- Row-Level Security ---
        // organizations : un client ne voit / ne modifie que sa propre organisation.
        // Le contexte est posé avant l'INSERT à l'inscription (l'identifiant est généré côté application).
        Rls::enable('organizations');
        DB::statement('CREATE POLICY tenant_isolation ON organizations FOR ALL TO nova_app USING (id = app_current_org()) WITH CHECK (id = app_current_org())');
        Rls::grantPlatformRead('organizations');
        // Le Super Admin change les statuts (suspension, blocage, suppression) : écriture explicite et bornée.
        DB::statement('CREATE POLICY platform_write ON organizations FOR UPDATE TO nova_platform USING (true) WITH CHECK (true)');
        DB::statement('GRANT UPDATE ON organizations TO nova_platform');

        foreach (['locations', 'org_members', 'org_member_locations'] as $table) {
            Rls::enableTenantTable($table);
        }

        // Recherche des rattachements d'un utilisateur AVANT que le contexte d'organisation existe (choix de l'organisation
        // à la connexion). Fonction SECURITY DEFINER volontairement étroite : elle ne renvoie que les colonnes nécessaires.
        // Le propriétaire de la fonction doit disposer de BYPASSRLS (ou être superuser).
        // DROP d'abord : migrate:fresh ne supprime pas les fonctions, et une version plus récente (avec les permissions, docs/34) peut exister.
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
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS auth_memberships(uuid)');
        Schema::dropIfExists('org_member_locations');
        Schema::dropIfExists('org_members');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('organizations');
    }
};
