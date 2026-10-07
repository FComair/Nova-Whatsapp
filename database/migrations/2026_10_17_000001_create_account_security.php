<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sécurité du compte client (flowchart 3C, décision 18) : authentification à deux facteurs (TOTP + codes de secours)
 * et changement d'adresse e-mail en deux temps (lien envoyé à la NOUVELLE adresse).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable();          // chiffré (cast « encrypted »)
            $table->text('two_factor_recovery_codes')->nullable();  // empreintes des codes restants, chiffrées
            $table->timestampTz('two_factor_confirmed_at')->nullable();
        });

        Schema::create('email_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('new_email');
            $table->string('token_hash');
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_changes');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
