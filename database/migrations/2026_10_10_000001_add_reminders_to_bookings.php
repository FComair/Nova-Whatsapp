<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rappel WhatsApp : posé par le cœur de réservation au moment de l'envoi (jamais deux rappels pour le même rendez-vous).
        Schema::table('bookings', function (Blueprint $table): void {
            $table->timestampTz('reminder_sent_at')->nullable();
            $table->unsignedSmallInteger('reminder_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['reminder_sent_at', 'reminder_attempts']);
        });
    }
};
