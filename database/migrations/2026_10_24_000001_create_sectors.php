<?php

declare(strict_types=1);

use App\Modules\Tenancy\Support\DefaultSectors;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Secteurs d'activité gérés par le Super Admin. Données globales, sans org_id : lecture seule pour l'application cliente,
 * écriture pour le rôle plateforme. Les établissements gardent le CODE du secteur (`organizations.sector`) : renommer un secteur
 * ne touche donc ni les établissements ni les modèles de services (config/sector_templates.php).
 *
 * Le nom saisi est le nom principal ; les traductions fr / nl / en sont facultatives (à défaut, le nom principal est affiché).
 * `name_key` (nom sans accents ni majuscules) impose l'unicité sans tenir compte de la casse ni des accents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sectors', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('code', 50)->unique();
            $table->string('name', 60);
            $table->string('name_key', 60)->unique();
            $table->string('name_fr', 60)->nullable();
            $table->string('name_nl', 60)->nullable();
            $table->string('name_en', 60)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
        });

        DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON sectors FROM nova_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON sectors TO nova_platform');

        DefaultSectors::insert();
    }

    public function down(): void
    {
        Schema::dropIfExists('sectors');
    }
};
