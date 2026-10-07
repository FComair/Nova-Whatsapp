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
        // Numéro WhatsApp Business d'un établissement (Meta Cloud API). Le jeton d'accès est chiffré au repos.
        Schema::create('whatsapp_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('phone_number_id', 40)->unique();
            $table->string('display_phone', 30)->nullable();
            $table->text('access_token_enc');
            $table->string('status', 20)->default('active');
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('org_id');
        });
        DB::statement("ALTER TABLE whatsapp_accounts ADD CONSTRAINT whatsapp_accounts_status_check CHECK (status IN ('active','disabled'))");
        Rls::enableTenantTable('whatsapp_accounts');

        // Journal des conversations. Le texte est chiffré au repos (données personnelles, parfois de santé).
        Schema::create('whatsapp_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('customer_id');
            $table->string('direction', 3);
            $table->string('wa_message_id', 120)->nullable();
            $table->string('type', 20)->default('text');
            $table->text('body_enc')->nullable();
            $table->string('status', 20);
            $table->string('error_code', 40)->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->index(['customer_id', 'created_at']);
        });
        DB::statement("ALTER TABLE whatsapp_messages ADD CONSTRAINT whatsapp_messages_direction_check CHECK (direction IN ('in','out'))");
        DB::statement("ALTER TABLE whatsapp_messages ADD CONSTRAINT whatsapp_messages_status_check CHECK (status IN ('received','queued','sent','delivered','read','failed'))");
        // Meta peut renvoyer la même notification : un identifiant de message n'est enregistré qu'une fois par établissement.
        DB::statement('CREATE UNIQUE INDEX whatsapp_messages_wa_id_unique ON whatsapp_messages (org_id, wa_message_id) WHERE wa_message_id IS NOT NULL');
        Rls::enableTenantTable('whatsapp_messages');

        // Désabonnement (STOP) du client final : plus aucun message de Nova tant qu'il ne se réabonne pas.
        Schema::table('customers', function (Blueprint $table): void {
            $table->timestampTz('whatsapp_opted_out_at')->nullable();
        });

        // Une notification Meta n'indique que l'identifiant du numéro : l'organisation est retrouvée par une fonction
        // SECURITY DEFINER étroite (aucun jeton renvoyé).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION whatsapp_account_lookup(p_phone_number_id text)
            RETURNS TABLE (id uuid, org_id uuid, status text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT a.id, a.org_id, a.status
                FROM whatsapp_accounts a
                WHERE a.phone_number_id = p_phone_number_id
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION whatsapp_account_lookup(text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION whatsapp_account_lookup(text) TO nova_app');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS whatsapp_account_lookup(text)');
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_opted_out_at');
        });
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
