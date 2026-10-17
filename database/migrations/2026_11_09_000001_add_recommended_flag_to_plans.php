<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formule recommandée à l'inscription (docs/10) : aucune par défaut. Elle n'est affichée comme telle que si l'établissement
 * Rendivoo l'a désignée explicitement (au plus une, garanti par la base).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->boolean('is_recommended')->default(false);
        });

        DB::statement('CREATE UNIQUE INDEX plans_one_recommended ON plans (is_recommended) WHERE is_recommended');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS plans_one_recommended');
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('is_recommended');
        });
    }
};
