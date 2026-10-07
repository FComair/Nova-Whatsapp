<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Politique de mot de passe (décision 18) : interdiction de réutiliser les 5 derniers mots de passe
 * et date du dernier changement (renouvellement recommandé tous les 12 mois). Seules les empreintes sont conservées.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('password_changed_at')->nullable();
        });

        DB::statement('UPDATE users SET password_changed_at = created_at');

        Schema::create('password_histories', function (Blueprint $table): void {
            $table->id(); // ordre d'insertion fiable, même à l'identique de l'horodatage
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('password');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_histories');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_changed_at');
        });
    }
};
