<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Imports de listes de prix. Une ligne par import : fichiers (le temps de l'import), lignes lues par l'IA, résultat (avant / après de
 * chaque service touché, pour pouvoir annuler). Les photos ne sont jamais conservées : seules les lignes extraites le sont.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->uuid('user_id')->nullable();
            $table->string('status', 12)->default('analysed');
            $table->jsonb('file_names');
            $table->jsonb('files')->nullable();
            $table->jsonb('lines');
            $table->jsonb('result')->nullable();
            $table->unsignedSmallInteger('added')->default(0);
            $table->unsignedSmallInteger('updated')->default(0);
            $table->unsignedSmallInteger('ignored')->default(0);
            $table->timestampTz('imported_at')->nullable();
            $table->timestampTz('undone_at')->nullable();
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->index(['org_id', 'created_at']);
        });
        DB::statement("ALTER TABLE price_list_imports ADD CONSTRAINT price_list_imports_status_check CHECK (status IN ('analysed','imported','undone','cancelled','expired'))");
        Rls::enableTenantTable('price_list_imports');
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_imports');
    }
};
