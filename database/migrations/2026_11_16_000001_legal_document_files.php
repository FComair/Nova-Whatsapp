<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents juridiques déposés en PDF (docs/35).
 *
 * Une version publiée est figée par la base (docs/28) : déposer un nouveau PDF crée donc une NOUVELLE version publiée, qui devient la version
 * en vigueur ; la précédente reste en base, « archivée » (simplement dépassée), jamais supprimée. Le fichier vit sur le disque privé : la
 * base garde son chemin aléatoire, son nom d'origine, sa taille, son empreinte SHA-256, qui l'a déposé et quand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_document_versions', function (Blueprint $table): void {
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 150)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestampTz('uploaded_at')->nullable();
        });
        DB::statement('ALTER TABLE legal_document_versions ADD CONSTRAINT legal_document_versions_file_check CHECK ((file_path IS NULL) = (file_sha256 IS NULL) AND (file_path IS NULL) = (file_name IS NULL) AND (file_path IS NULL) = (file_size IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE legal_document_versions DROP CONSTRAINT IF EXISTS legal_document_versions_file_check');
        // Les versions publiées sont figées : on ne retire que les colonnes (les fichiers restent sur le disque privé).
        Schema::table('legal_document_versions', function (Blueprint $table): void {
            $table->dropColumn(['file_path', 'file_name', 'file_size', 'file_sha256', 'uploaded_by', 'uploaded_at']);
        });
    }
};
