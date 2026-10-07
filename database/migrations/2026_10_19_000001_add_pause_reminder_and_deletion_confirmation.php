<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Pause d'établissement (flowchart 4B) : rappel avant la fin, envoyé une seule fois.
 * - Suppression de compte (flowchart 4C, décision 12) : confirmation par un lien e-mail de 24 h, à usage unique (seule l'empreinte du jeton est conservée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->timestampTz('pause_reminder_sent_at')->nullable();
            $table->string('deletion_confirm_token_hash')->nullable();
            $table->timestampTz('deletion_confirm_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['pause_reminder_sent_at', 'deletion_confirm_token_hash', 'deletion_confirm_expires_at']);
        });
    }
};
