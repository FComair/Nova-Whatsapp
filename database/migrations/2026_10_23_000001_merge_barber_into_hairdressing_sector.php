<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les secteurs « Coiffure » et « Barbier » sont fusionnés en un seul : « Coiffeur & Barbier » (code `hairdressing`).
 * Les établissements déjà inscrits comme barbiers passent dans le secteur fusionné. Leurs services restent inchangés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')->where('sector', 'barber')->update(['sector' => 'hairdressing']);
    }

    public function down(): void
    {
        // Irréversible : on ne sait plus quels établissements étaient des barbiers.
    }
};
