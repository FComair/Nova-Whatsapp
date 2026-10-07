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
        // Clients finaux d'une organisation (docs/01 §4.6). Identifiés par leur numéro WhatsApp (E.164), unique par organisation.
        Schema::create('customers', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->string('phone_e164', 20);
            $table->string('name', 120)->nullable();
            $table->string('email')->nullable();
            $table->string('language', 2)->nullable(); // fr, nl, en : détectée par message, conservée sur le client
            $table->jsonb('tags')->default('[]');
            $table->boolean('is_blocked')->default(false);
            $table->timestampTz('anonymized_at')->nullable();
            $table->timestampsTz();
            $table->timestampTz('deleted_at')->nullable();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index('org_id');
        });
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_phone_check CHECK (phone_e164 ~ '^\\+[1-9][0-9]{7,14}$')");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_language_check CHECK (language IS NULL OR language IN ('fr','nl','en'))");
        DB::statement('CREATE UNIQUE INDEX customers_phone_unique ON customers (org_id, phone_e164) WHERE deleted_at IS NULL');

        // Exceptions aux horaires d'ouverture (docs/01 §4.4) : jour férié / fermeture (is_closed) ou ouverture exceptionnelle
        // (plages qui REMPLACENT les horaires habituels ce jour-là).
        Schema::create('business_hours_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('location_id');
            $table->date('date');
            $table->boolean('is_closed')->default(true);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('label', 120)->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->index(['location_id', 'date']);
        });
        DB::statement('ALTER TABLE business_hours_exceptions ADD CONSTRAINT bhe_shape_check CHECK ((is_closed AND starts_at IS NULL AND ends_at IS NULL) OR (NOT is_closed AND starts_at IS NOT NULL AND ends_at IS NOT NULL AND ends_at > starts_at))');

        // Indisponibilités et disponibilités exceptionnelles d'un praticien (docs/01 §4.2).
        Schema::create('practitioner_time_off', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('practitioner_id');
            $table->string('kind', 20); // unavailable | extra
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('practitioner_id')->references('id')->on('practitioners')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['practitioner_id', 'starts_at']);
        });
        DB::statement("ALTER TABLE practitioner_time_off ADD CONSTRAINT time_off_kind_check CHECK (kind IN ('unavailable','extra'))");
        DB::statement('ALTER TABLE practitioner_time_off ADD CONSTRAINT time_off_range_check CHECK (ends_at > starts_at)');

        foreach (['customers', 'business_hours_exceptions', 'practitioner_time_off'] as $table) {
            Rls::enableTenantTable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('practitioner_time_off');
        Schema::dropIfExists('business_hours_exceptions');
        Schema::dropIfExists('customers');
    }
};
