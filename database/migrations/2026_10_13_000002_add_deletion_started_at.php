<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Posé par le pipeline quand l'effacement commence : à partir de là, la suppression ne peut plus être annulée
        // (le rôle applicatif lit cette colonne ; il n'a pas accès aux tables de suivi).
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestampTz('deletion_started_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('deletion_started_at');
        });
    }
};
