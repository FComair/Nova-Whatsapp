<?php

declare(strict_types=1);

use App\Support\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mesure de la consommation WhatsApp par établissement (décision du fondateur : Rendivoo refacture les conversations). Compteur mensuel par
 * catégorie (modèle « utility », réponse libre « service ») et par pays du destinataire, les tarifs de Meta variant avec ces deux critères.
 * Aucune donnée personnelle : seulement des compteurs, conservés comme les autres données de facturation (docs/09).
 * Le prix refacturé n'est pas encore fixé : il vivra dans `platform_settings`, jamais dans le code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_usage', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('org_id');
            $table->date('month');
            $table->string('category', 12);
            $table->string('country', 2);
            $table->unsignedInteger('messages')->default(0);
            $table->timestampsTz();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->unique(['org_id', 'month', 'category', 'country']);
        });
        DB::statement("ALTER TABLE whatsapp_usage ADD CONSTRAINT whatsapp_usage_category_check CHECK (category IN ('utility','service','marketing','authentication'))");
        Rls::enableTenantTable('whatsapp_usage');
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_usage');
    }
};
