<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pourboires des praticiens (docs/24). Un montant SÉPARÉ du prix et du montant encaissé du rendez-vous : `bookings.price_cents` et
 * `bookings.amount_collected_cents` ne changent jamais à cause d'un pourboire.
 *
 * - `booking_tips` : au plus UN pourboire par rendez-vous (le paiement du rendez-vous est lui-même unique : pas de second
 *   enregistrement possible). Il garde la devise, le client, la date d'enregistrement (`paid_at`) et l'auteur.
 * - `booking_tip_allocations` : à qui revient le pourboire, par praticien. La somme des parts est égale au pourboire : garantie par
 *   la base (déclencheurs différés), pas seulement par le code.
 * Le journal des modifications est dans `audit_logs` et `booking_events` (ajout seul).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_tips', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('booking_id');
            $table->uuid('customer_id');
            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);
            $table->timestampTz('paid_at');
            $table->uuid('recorded_by')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings');
            $table->foreign('customer_id')->references('id')->on('customers');
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('booking_id');
            $table->index(['org_id', 'paid_at']);
        });
        DB::statement('ALTER TABLE booking_tips ADD CONSTRAINT booking_tips_amount_check CHECK (amount_cents > 0)');

        Schema::create('booking_tip_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('tip_id');
            $table->uuid('booking_id');
            $table->uuid('practitioner_id');
            $table->unsignedInteger('amount_cents');
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('tip_id')->references('id')->on('booking_tips')->cascadeOnDelete();
            $table->foreign('booking_id')->references('id')->on('bookings');
            // Pas de suppression en cascade : le praticien qui a reçu un pourboire reste dans l'historique.
            $table->foreign('practitioner_id')->references('id')->on('practitioners');
            $table->unique(['tip_id', 'practitioner_id']);
            $table->index(['practitioner_id']);
            $table->index(['booking_id']);
        });
        DB::statement('ALTER TABLE booking_tip_allocations ADD CONSTRAINT booking_tip_allocations_amount_check CHECK (amount_cents > 0)');

        // Les parts doivent toujours totaliser le pourboire : contrôlé à la fin de la transaction, pour que le code puisse écrire
        // le pourboire puis ses parts dans l'ordre qu'il veut.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION booking_tip_check_allocations() RETURNS trigger AS $$
            DECLARE
                target uuid;
                expected integer;
                actual integer;
            BEGIN
                IF TG_TABLE_NAME = 'booking_tips' THEN
                    target := COALESCE(NEW.id, OLD.id);
                ELSE
                    target := COALESCE(NEW.tip_id, OLD.tip_id);
                END IF;

                SELECT amount_cents INTO expected FROM booking_tips WHERE id = target;

                IF expected IS NULL THEN
                    RETURN NULL; -- pourboire supprimé : ses parts partent avec lui
                END IF;

                SELECT COALESCE(SUM(amount_cents), 0) INTO actual FROM booking_tip_allocations WHERE tip_id = target;

                IF actual <> expected THEN
                    RAISE EXCEPTION 'Les parts du pourboire (%) ne totalisent pas le pourboire (%).', actual, expected USING ERRCODE = '23514';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER booking_tips_allocations_sum AFTER INSERT OR UPDATE ON booking_tips DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION booking_tip_check_allocations()');
        DB::statement('CREATE CONSTRAINT TRIGGER booking_tip_allocations_sum AFTER INSERT OR UPDATE OR DELETE ON booking_tip_allocations DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION booking_tip_check_allocations()');

        Rls::enableTenantTable('booking_tips');
        Rls::enableTenantTable('booking_tip_allocations');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS booking_tip_allocations_sum ON booking_tip_allocations');
        DB::statement('DROP TRIGGER IF EXISTS booking_tips_allocations_sum ON booking_tips');
        DB::statement('DROP FUNCTION IF EXISTS booking_tip_check_allocations()');
        Schema::dropIfExists('booking_tip_allocations');
        Schema::dropIfExists('booking_tips');
    }
};
