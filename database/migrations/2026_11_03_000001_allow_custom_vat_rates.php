<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Taux de TVA libre (0 à 100 %, deux décimales au plus) : le client peut saisir son propre taux au lieu de se limiter à
 * 0, 6, 12 ou 21 %. Le service et l'instantané du rendez-vous (qui garde le taux au moment de la réservation) passent de
 * l'entier au décimal ; les valeurs existantes sont conservées telles quelles.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_vat_check');
        DB::statement('ALTER TABLE services ALTER COLUMN vat_rate TYPE numeric(5,2)');
        DB::statement('ALTER TABLE services ALTER COLUMN vat_rate SET DEFAULT 21');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_vat_check CHECK (vat_rate >= 0 AND vat_rate <= 100)');

        DB::statement('ALTER TABLE bookings ALTER COLUMN vat_rate TYPE numeric(5,2)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_vat_check');
        DB::statement('ALTER TABLE services ALTER COLUMN vat_rate TYPE smallint USING round(vat_rate)::smallint');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_vat_check CHECK (vat_rate IN (0, 6, 12, 21))');
        DB::statement('ALTER TABLE bookings ALTER COLUMN vat_rate TYPE smallint USING round(vat_rate)::smallint');
    }
};
