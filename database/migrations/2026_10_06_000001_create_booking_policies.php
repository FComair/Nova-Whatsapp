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
        // Politiques de réservation d'une organisation (docs/01 §4.5, flowchart 1C étape 6) : une ligne par organisation.
        Schema::create('booking_policies', function (Blueprint $table): void {
            $table->uuid('org_id')->primary();
            $table->unsignedSmallInteger('min_notice_hours')->default(2);        // délai minimum avant un rendez-vous réservable
            $table->unsignedSmallInteger('max_horizon_days')->default(60);       // jusqu'à quand on peut réserver à l'avance
            $table->unsignedSmallInteger('cancellation_window_hours')->default(24); // annulation / report possibles jusqu'à X h avant
            $table->unsignedInteger('no_show_fee_cents')->default(0);            // frais indicatifs en cas de no-show (paiement sur place)
            $table->boolean('allow_overbooking')->default(false);                // surbooking (créneaux au-delà de la capacité)
            $table->unsignedSmallInteger('hold_minutes')->default(10);           // blocage d'un créneau pendant la conversation (5 à 10 min)
            $table->unsignedSmallInteger('reminder_hours_before')->default(24);  // rappel avant le rendez-vous (0 = aucun rappel)
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_notice_check CHECK (min_notice_hours <= 168)');
        DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_horizon_check CHECK (max_horizon_days BETWEEN 1 AND 365)');
        DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_cancellation_check CHECK (cancellation_window_hours <= 168)');
        DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_fee_check CHECK (no_show_fee_cents <= 50000)');
        DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_hold_check CHECK (hold_minutes BETWEEN 5 AND 10)');
        DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_reminder_check CHECK (reminder_hours_before <= 72)');
        // Le délai minimum doit rester inférieur à l'horizon (sinon aucune date ne serait réservable).
        DB::statement('ALTER TABLE booking_policies ADD CONSTRAINT booking_policies_notice_lt_horizon_check CHECK (min_notice_hours < max_horizon_days * 24)');

        Rls::enableTenantTable('booking_policies');
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_policies');
    }
};
