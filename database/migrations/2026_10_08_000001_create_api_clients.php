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
        // Clients de l'API interne (n8n, passerelle WhatsApp) : une clé publique, un secret partagé pour signer (HMAC) et des
        // périmètres (scopes). Le secret est chiffré au repos : il doit rester lisible côté serveur pour vérifier les signatures.
        Schema::create('api_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('name', 120);
            $table->string('key_id', 40)->unique();
            $table->text('secret_enc');
            $table->jsonb('scopes')->default('[]');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index('org_id');
        });

        Rls::enableTenantTable('api_clients');

        // L'organisation n'est pas connue avant d'avoir lu le client : recherche par clé publique via une fonction SECURITY DEFINER
        // étroite (mêmes précautions que invitation_lookup).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION api_client_lookup(p_key_id text)
            RETURNS TABLE (id uuid, org_id uuid, secret_enc text, scopes jsonb, revoked_at timestamptz)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT c.id, c.org_id, c.secret_enc, c.scopes, c.revoked_at
                FROM api_clients c
                WHERE c.key_id = p_key_id
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION api_client_lookup(text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION api_client_lookup(text) TO nova_app');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS api_client_lookup(text)');
        Schema::dropIfExists('api_clients');
    }
};
