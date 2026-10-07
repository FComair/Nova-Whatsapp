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
        Schema::create('service_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('org_id');
        });
        // Un nom de catégorie par organisation, sans tenir compte de la casse.
        DB::statement('CREATE UNIQUE INDEX service_categories_name_unique ON service_categories (org_id, lower(name))');

        // Services réservables (docs/01 §4.3). Montants en centimes ; durée et marges en minutes.
        Schema::create('services', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('category_id')->nullable();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->unsignedInteger('price_cents');
            $table->unsignedSmallInteger('vat_rate')->default(21); // pourcentage : 0, 6, 12 ou 21 (Belgique)
            $table->char('currency', 3)->default('EUR');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->boolean('bookable_online')->default(true);
            $table->boolean('notes_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('category_id')->references('id')->on('service_categories')->nullOnDelete();
            $table->index(['org_id', 'is_active']);
        });
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_duration_check CHECK (duration_minutes BETWEEN 5 AND 480)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_buffers_check CHECK (buffer_before_minutes <= 120 AND buffer_after_minutes <= 120)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_capacity_check CHECK (capacity BETWEEN 1 AND 50)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_vat_check CHECK (vat_rate IN (0, 6, 12, 21))');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_price_check CHECK (price_cents <= 100000000)');

        Rls::enableTenantTable('service_categories');
        Rls::enableTenantTable('services');
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
