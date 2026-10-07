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
        // Réservations (docs/01 §4.5). Horodatages en UTC ; le fuseau du site sert à l'affichage et au calcul des créneaux.
        // `blocked_from` / `blocked_until` = le rendez-vous ÉLARGI de ses marges avant et après : c'est cet intervalle qui
        // empêche le double-booking, pour que les marges du service soient respectées entre deux rendez-vous.
        Schema::create('bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('location_id');
            $table->uuid('customer_id');
            $table->uuid('practitioner_id');
            $table->uuid('service_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('blocked_from');
            $table->timestampTz('blocked_until');
            $table->string('status', 30)->default('confirmed');
            $table->string('source', 20);
            $table->unsignedInteger('price_cents');           // prix au moment de la réservation (instantané)
            $table->unsignedSmallInteger('vat_rate');
            $table->char('currency', 3)->default('EUR');
            $table->string('payment_status', 20)->default('unpaid'); // paiement sur place : suivi seulement
            $table->unsignedInteger('amount_collected_cents')->default(0);
            $table->string('payment_method', 30)->nullable();
            $table->text('notes_enc')->nullable();            // notes chiffrées au repos (cast `encrypted`)
            $table->timestampTz('expires_at')->nullable();   // fin du blocage d'un créneau en attente de confirmation
            $table->string('idempotency_key', 100)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            // Pas de suppression en cascade des acteurs : l'historique des rendez-vous doit rester intact.
            $table->foreign('location_id')->references('id')->on('locations');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('practitioner_id')->references('id')->on('practitioners');
            $table->foreign('service_id')->references('id')->on('services');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['org_id', 'starts_at']);
            $table->index(['practitioner_id', 'starts_at']);
            $table->index(['customer_id', 'starts_at']);
        });

        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_status_check CHECK (status IN ('pending','confirmed','cancelled_by_customer','cancelled_by_business','no_show','completed','expired'))");
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_source_check CHECK (source IN ('dashboard','whatsapp','staff_whatsapp','api'))");
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_payment_status_check CHECK (payment_status IN ('unpaid','paid_onsite','partially_paid','waived','written_off'))");
        DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_range_check CHECK (ends_at > starts_at AND blocked_from <= starts_at AND blocked_until >= ends_at)');
        DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_pending_expiry_check CHECK (status <> 'pending' OR expires_at IS NOT NULL)");
        DB::statement('CREATE UNIQUE INDEX bookings_idempotency_unique ON bookings (org_id, idempotency_key) WHERE idempotency_key IS NOT NULL');

        // PREVENTION DU DOUBLE-BOOKING PAR LA BASE (docs/01 §6) : un praticien ne peut pas avoir deux rendez-vous en attente ou
        // confirmés dont les intervalles (marges comprises) se chevauchent. Vrai même en cas de requêtes simultanées.
        DB::statement(<<<'SQL'
            ALTER TABLE bookings ADD CONSTRAINT bookings_no_double_booking
            EXCLUDE USING gist (practitioner_id WITH =, tstzrange(blocked_from, blocked_until) WITH &&)
            WHERE (status IN ('pending', 'confirmed'))
        SQL);

        // Journal d'audit des réservations : ajout seul (docs/01 §4.5).
        Schema::create('booking_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('booking_id');
            $table->string('actor_type', 30);
            $table->uuid('actor_id')->nullable();
            $table->string('action', 40);
            $table->jsonb('diff')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings');
            $table->index(['booking_id', 'created_at']);
        });
        DB::statement("ALTER TABLE booking_events ADD CONSTRAINT booking_events_actor_check CHECK (actor_type IN ('user','customer','staff_whatsapp','system','api'))");
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON booking_events FROM nova_app');
        DB::statement('CREATE TRIGGER booking_events_append_only BEFORE UPDATE OR DELETE ON booking_events FOR EACH ROW EXECUTE FUNCTION forbid_modification()');
        DB::statement('CREATE TRIGGER booking_events_no_truncate BEFORE TRUNCATE ON booking_events FOR EACH STATEMENT EXECUTE FUNCTION forbid_modification()');

        Rls::enableTenantTable('bookings');
        Rls::enableTenantTable('booking_events');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS booking_events_no_truncate ON booking_events');
        DB::statement('DROP TRIGGER IF EXISTS booking_events_append_only ON booking_events');
        Schema::dropIfExists('booking_events');
        Schema::dropIfExists('bookings');
    }
};
