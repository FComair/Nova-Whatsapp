<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Filament stocke l'authentification à deux facteurs (TOTP) dans ces deux colonnes, chiffrées par cast Eloquent.
        // Le 2FA est actif quand `app_authentication_secret` est renseigné : la colonne de confirmation devient inutile.
        Schema::table('platform_admins', function (Blueprint $table): void {
            $table->renameColumn('totp_secret_enc', 'app_authentication_secret');
            $table->renameColumn('recovery_codes_enc', 'app_authentication_recovery_codes');
            $table->dropColumn('totp_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table): void {
            $table->renameColumn('app_authentication_secret', 'totp_secret_enc');
            $table->renameColumn('app_authentication_recovery_codes', 'recovery_codes_enc');
            $table->timestampTz('totp_confirmed_at')->nullable();
        });
    }
};
