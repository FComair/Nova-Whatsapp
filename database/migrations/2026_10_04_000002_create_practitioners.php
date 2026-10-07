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
        // Praticiens (docs/01 §4.2). `draft` : ajouté au-delà de la limite du plan, non réservable (flowchart E8).
        Schema::create('practitioners', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('user_id')->nullable(); // compte de connexion lié (le propriétaire lui-même, ou un praticien invité)
            $table->string('name', 120);
            $table->text('bio')->nullable();
            $table->char('color', 7)->default('#2A5BD7'); // couleur dans le planning
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['org_id', 'status']);
        });
        DB::statement("ALTER TABLE practitioners ADD CONSTRAINT practitioners_status_check CHECK (status IN ('active','draft','inactive'))");
        DB::statement("ALTER TABLE practitioners ADD CONSTRAINT practitioners_color_check CHECK (color ~ '^#[0-9A-Fa-f]{6}$')");
        // Un compte de connexion ne correspond qu'à un praticien par organisation.
        DB::statement('CREATE UNIQUE INDEX practitioners_one_per_user ON practitioners (org_id, user_id) WHERE user_id IS NOT NULL AND deleted_at IS NULL');

        // Services proposés par un praticien, avec durée et prix éventuellement personnalisés.
        Schema::create('practitioner_services', function (Blueprint $table): void {
            $table->uuid('org_id');
            $table->uuid('practitioner_id');
            $table->uuid('service_id');
            $table->unsignedSmallInteger('custom_duration_minutes')->nullable();
            $table->unsignedInteger('custom_price_cents')->nullable();

            $table->primary(['practitioner_id', 'service_id']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('practitioner_id')->references('id')->on('practitioners')->cascadeOnDelete();
            $table->foreign('service_id')->references('id')->on('services')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE practitioner_services ADD CONSTRAINT practitioner_services_duration_check CHECK (custom_duration_minutes IS NULL OR custom_duration_minutes BETWEEN 5 AND 480)');

        // Disponibilité hebdomadaire récurrente (docs/01 §4.2). Un praticien ne peut pas être à deux endroits en même temps :
        // aucun chevauchement sur un même jour, tous sites confondus (contrainte d'exclusion).
        Schema::create('practitioner_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('practitioner_id');
            $table->uuid('location_id');
            $table->unsignedSmallInteger('weekday'); // 1 = lundi ... 7 = dimanche
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('practitioner_id')->references('id')->on('practitioners')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->index(['practitioner_id', 'location_id']);
        });
        DB::statement('ALTER TABLE practitioner_schedules ADD CONSTRAINT practitioner_schedules_weekday_check CHECK (weekday BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE practitioner_schedules ADD CONSTRAINT practitioner_schedules_range_check CHECK (ends_at > starts_at)');
        DB::statement(<<<'SQL'
            ALTER TABLE practitioner_schedules ADD CONSTRAINT practitioner_schedules_no_overlap
            EXCLUDE USING gist (practitioner_id WITH =, weekday WITH =, timerange(starts_at, ends_at) WITH &&)
        SQL);

        foreach (['practitioners', 'practitioner_services', 'practitioner_schedules'] as $table) {
            Rls::enableTenantTable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_schedules');
        Schema::dropIfExists('practitioner_services');
        Schema::dropIfExists('practitioners');
    }
};
