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
        // Journal d'audit en ajout seul (docs/01 §11) : toute modification administrative ou sensible y est tracée.
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id')->nullable();
            $table->string('actor_type', 30); // user, platform_admin, system, staff_whatsapp, api
            $table->uuid('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->jsonb('diff')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['org_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_check CHECK (actor_type IN ('user','platform_admin','system','staff_whatsapp','api'))");

        // Ajout seul : aucun UPDATE / DELETE / TRUNCATE pour les rôles applicatifs, doublé d'un trigger de garde.
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM nova_app');
        DB::statement('GRANT SELECT, INSERT ON audit_logs TO nova_platform');
        DB::statement('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION forbid_modification()');
        DB::statement('CREATE TRIGGER audit_logs_no_truncate BEFORE TRUNCATE ON audit_logs FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification()');

        Rls::enable('audit_logs');
        // Un client lit uniquement les événements de son organisation ; il peut écrire les siens (ou sans organisation, ex. connexion).
        DB::statement('CREATE POLICY tenant_read ON audit_logs FOR SELECT TO nova_app USING (org_id = app_current_org())');
        DB::statement('CREATE POLICY tenant_insert ON audit_logs FOR INSERT TO nova_app WITH CHECK (org_id IS NULL OR org_id = app_current_org())');
        DB::statement('CREATE POLICY platform_read ON audit_logs FOR SELECT TO nova_platform USING (true)');
        DB::statement('CREATE POLICY platform_insert ON audit_logs FOR INSERT TO nova_platform WITH CHECK (true)');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_no_truncate ON audit_logs');
        DB::statement('DROP TRIGGER IF EXISTS audit_logs_append_only ON audit_logs');
        Schema::dropIfExists('audit_logs');
    }
};
