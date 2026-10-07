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
        // Calendrier externe d'un praticien (Google, Outlook, Yahoo, Calendly, Doctena, Rosa… via ICS ou CalDAV), en lecture seule.
        // L'adresse et les identifiants sont chiffrés au repos : une adresse ICS privée donne accès à tout le calendrier.
        Schema::create('external_calendars', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('practitioner_id');
            $table->string('name', 120);
            $table->string('kind', 10);
            $table->text('url_enc');
            $table->text('username_enc')->nullable();
            $table->text('password_enc')->nullable();
            $table->string('status', 10)->default('active');
            $table->timestampTz('last_synced_at')->nullable();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->string('last_error', 40)->nullable();
            $table->unsignedSmallInteger('unsupported_rules')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('practitioner_id')->references('id')->on('practitioners')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['org_id', 'practitioner_id']);
        });
        DB::statement("ALTER TABLE external_calendars ADD CONSTRAINT external_calendars_kind_check CHECK (kind IN ('ics','caldav'))");
        DB::statement("ALTER TABLE external_calendars ADD CONSTRAINT external_calendars_status_check CHECK (status IN ('active','error','paused'))");
        Rls::enableTenantTable('external_calendars');

        // Plages occupées du calendrier externe : uniquement des horaires, jamais le contenu des événements.
        Schema::create('external_busy_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('calendar_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('calendar_id')->references('id')->on('external_calendars')->cascadeOnDelete();
            $table->index(['calendar_id', 'starts_at']);
        });
        DB::statement('ALTER TABLE external_busy_slots ADD CONSTRAINT external_busy_slots_range_check CHECK (ends_at > starts_at)');
        Rls::enableTenantTable('external_busy_slots');

        // Flux ICS sortant d'un praticien (ses rendez-vous Nova, sans donnée de client), lu par ses autres outils. Adresse secrète
        // par jeton : l'empreinte sert à la retrouver, le jeton chiffré permet de le réafficher au propriétaire.
        Schema::create('calendar_feeds', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('practitioner_id')->unique();
            $table->char('token_hash', 64)->unique();
            $table->text('token_enc');
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('practitioner_id')->references('id')->on('practitioners')->cascadeOnDelete();
        });
        Rls::enableTenantTable('calendar_feeds');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION calendar_feed_lookup(p_token_hash text)
            RETURNS TABLE (org_id uuid, practitioner_id uuid)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$
                SELECT f.org_id, f.practitioner_id FROM calendar_feeds f WHERE f.token_hash = p_token_hash
            $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION calendar_feed_lookup(text) FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION calendar_feed_lookup(text) TO nova_app');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS calendar_feed_lookup(text)');
        Schema::dropIfExists('calendar_feeds');
        Schema::dropIfExists('external_busy_slots');
        Schema::dropIfExists('external_calendars');
    }
};
