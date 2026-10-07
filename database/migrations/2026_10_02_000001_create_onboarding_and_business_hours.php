<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Progression de l'assistant en 9 étapes, reprenable à chaque connexion (flowcharts 1B à 1D, 2A).
        Schema::create('onboarding_progress', function (Blueprint $table): void {
            $table->uuid('org_id')->primary();
            $table->jsonb('steps')->default('{}'); // { "company": "done", "locations": "done", ... }
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('went_live_at')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        // Type intervalle sur des heures : permet une contrainte d'exclusion (aucun chevauchement de plages).
        // Idempotent : `migrate:fresh` supprime les tables mais pas les types PostgreSQL.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'timerange') THEN
                    CREATE TYPE timerange AS RANGE (subtype = time);
                END IF;
            END
            $$
        SQL);

        // Horaires d'ouverture hebdomadaires d'un site, plusieurs plages par jour possibles (docs/01 §4.4).
        // weekday : 1 = lundi ... 7 = dimanche (ISO 8601). Une plage ne traverse pas minuit.
        Schema::create('business_hours', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('location_id');
            $table->unsignedSmallInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->index(['location_id', 'weekday']);
        });
        DB::statement('ALTER TABLE business_hours ADD CONSTRAINT business_hours_weekday_check CHECK (weekday BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE business_hours ADD CONSTRAINT business_hours_range_check CHECK (ends_at > starts_at)');
        DB::statement(<<<'SQL'
            ALTER TABLE business_hours ADD CONSTRAINT business_hours_no_overlap
            EXCLUDE USING gist (location_id WITH =, weekday WITH =, timerange(starts_at, ends_at) WITH &&)
        SQL);

        Rls::enableTenantTable('onboarding_progress');
        Rls::enableTenantTable('business_hours');
    }

    public function down(): void
    {
        Schema::dropIfExists('business_hours');
        DB::statement('DROP TYPE IF EXISTS timerange');
        Schema::dropIfExists('onboarding_progress');
    }
};
