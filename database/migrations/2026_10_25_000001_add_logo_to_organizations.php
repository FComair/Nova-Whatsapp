<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logo de l'établissement. Les fichiers (deux tailles) vivent dans le stockage privé, dans un dossier par établissement ;
 * la base ne garde que la date du dernier envoi : vide = pas de logo, sinon c'est aussi le numéro de version qui force les
 * navigateurs à recharger l'image quand elle change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestampTz('logo_updated_at')->nullable()->after('billing_address');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('logo_updated_at');
        });
    }
};
