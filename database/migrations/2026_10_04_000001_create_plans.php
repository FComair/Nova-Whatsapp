<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Catalogue des offres (docs/01 §9). Données globales, sans org_id : lecture seule pour l'application cliente.
        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->unsignedInteger('price_monthly_cents');
            $table->unsignedInteger('price_yearly_cents')->nullable(); // remise annuelle non arrêtée : à définir
            $table->char('currency', 3)->default('EUR');
            // Limites incluses : practitioners, locations, conversations, appointments (null = illimité).
            $table->jsonb('limits');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();
        });

        DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON plans FROM nova_app');
        DB::statement('GRANT SELECT ON plans TO nova_platform');

        // Offres initiales validées par le fondateur : Basic 29,99 € et Business 49,99 € (hors TVA).
        // Limites de praticiens : plan de tarification (Basic 1, Business jusqu'à 5). Volumes de conversations et de
        // rendez-vous : HYPOTHÈSES du plan de tarification, à confirmer avec les clients pilotes.
        DB::table('plans')->insert([
            [
                'id' => (string) Str::uuid7(), 'code' => 'basic', 'name' => 'Basic', 'price_monthly_cents' => 2999, 'position' => 1,
                'limits' => json_encode(['practitioners' => 1, 'locations' => 1, 'conversations' => 150, 'appointments' => 90]),
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid7(), 'code' => 'business', 'name' => 'Business', 'price_monthly_cents' => 4999, 'position' => 2,
                'limits' => json_encode(['practitioners' => 5, 'locations' => 1, 'conversations' => 600, 'appointments' => 380]),
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        Schema::table('organizations', function (Blueprint $table): void {
            $table->foreign('plan_id')->references('id')->on('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropForeign(['plan_id']);
        });
        Schema::dropIfExists('plans');
    }
};
