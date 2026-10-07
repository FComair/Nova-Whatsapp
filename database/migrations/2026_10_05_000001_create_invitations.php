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
        // Invitations de praticiens par lien (flowcharts 1C et 5B/E13). Le jeton n'est JAMAIS stocké : seule son empreinte l'est.
        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('practitioner_id');
            $table->uuid('invited_by')->nullable();
            $table->string('role', 30)->default('practitioner');
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable(); // nouvelle invitation : l'ancienne est invalidée
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('practitioner_id')->references('id')->on('practitioners')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['org_id', 'practitioner_id']);
        });
        DB::statement('ALTER TABLE invitations ADD COLUMN email citext NOT NULL');
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_check CHECK (role IN ('practitioner'))");
        // Une seule invitation en attente par praticien.
        DB::statement('CREATE UNIQUE INDEX invitations_one_pending_per_practitioner ON invitations (practitioner_id) WHERE used_at IS NULL AND revoked_at IS NULL');

        Rls::enableTenantTable('invitations');

        // Le praticien invité n'est pas connecté : l'organisation n'est donc pas connue avant de lire l'invitation.
        // Fonction SECURITY DEFINER volontairement étroite : elle ne renvoie que la ligne dont l'empreinte est fournie,
        // avec les colonnes nécessaires. Le propriétaire de la fonction doit disposer de BYPASSRLS (ou être superuser).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION invitation_lookup(p_token_hash text)
            RETURNS TABLE (id uuid, org_id uuid, practitioner_id uuid, email citext, expires_at timestamptz, used_at timestamptz, revoked_at timestamptz)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT i.id, i.org_id, i.practitioner_id, i.email, i.expires_at, i.used_at, i.revoked_at
                FROM invitations i
                WHERE i.token_hash = p_token_hash
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION invitation_lookup(text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION invitation_lookup(text) TO nova_app');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS invitation_lookup(text)');
        Schema::dropIfExists('invitations');
    }
};
