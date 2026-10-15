<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Interrupteurs de sécurité du Super Admin (Paramètres globaux), tant qu'aucun serveur d'e-mail n'est en place :
 * vérification de l'e-mail des clients, 2FA des clients, 2FA du Super Admin. 1 = exigé (valeur par défaut), 0 = désactivé.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['email_verification_required', 'client_two_factor_required', 'admin_two_factor_required'] as $key) {
            DB::table('platform_settings')->insertOrIgnore(['key' => $key, 'value' => '1', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('platform_settings')->whereIn('key', ['email_verification_required', 'client_two_factor_required', 'admin_two_factor_required'])->delete();
    }
};
