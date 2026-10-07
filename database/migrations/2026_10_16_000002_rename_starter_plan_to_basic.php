<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Décision 8 : la formule d'entrée s'appelle « Basic » (et non « Starter »). Sans effet sur une base déjà à jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE plans SET code = 'basic', name = 'Basic' WHERE code = 'starter'");
        DB::statement("UPDATE organizations SET signup_plan = 'basic' WHERE signup_plan = 'starter'");
    }

    public function down(): void
    {
        DB::statement("UPDATE organizations SET signup_plan = 'starter' WHERE signup_plan = 'basic'");
        DB::statement("UPDATE plans SET code = 'starter', name = 'Starter' WHERE code = 'basic'");
    }
};
