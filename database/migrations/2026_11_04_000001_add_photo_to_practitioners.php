<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Photo de profil du praticien. Le fichier vit sur le disque privé (practitioners/{établissement}/{praticien}/{taille}.jpg) ;
 * la base ne garde que la date de dernier envoi, qui sert aussi de version pour renouveler le cache des navigateurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE practitioners ADD COLUMN photo_updated_at timestamptz NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE practitioners DROP COLUMN IF EXISTS photo_updated_at');
    }
};
