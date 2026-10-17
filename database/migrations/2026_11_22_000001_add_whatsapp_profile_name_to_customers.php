<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nom de profil WhatsApp d'un client (docs/41). Le profil que le client s'est donné dans WhatsApp (souvent un surnom) n'est plus
 * enregistré dans `customers.name` : il vit ici, mis à jour à chaque message, et ne sert que de DERNIER RECOURS quand aucun nom n'existe
 * dans la fiche (voir Customer::contactName). Donnée personnelle : exportée et effacée avec la fiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('whatsapp_profile_name', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('whatsapp_profile_name');
        });
    }
};
