<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Interrupteur « vérification du téléphone » des Paramètres globaux (1 = exigée, valeur par défaut ; 0 = désactivée). */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_settings')->insertOrIgnore(['key' => 'phone_verification_required', 'value' => '1', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('platform_settings')->where('key', 'phone_verification_required')->delete();
    }
};
