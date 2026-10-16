<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bibliothèque de messages WhatsApp gérée par le Super Admin, un ensemble indépendant par secteur d'activité (docs/39).
 *
 * Données globales, sans org_id : lecture seule pour l'application cliente (envoi), écriture pour le rôle plateforme.
 * Un modèle appartient à UN secteur (`sector_code`) : il n'apparaît pas dans un autre et n'y est pas utilisable ; dupliquer vers un
 * autre secteur crée une copie indépendante. Les corps (un par langue) utilisent des variables nommées `{{customer_name}}`, converties
 * en `{{1}}`, `{{2}}`… au moment d'être soumises à Meta.
 *
 * `message_template_events` est un journal en ajout seul (aucun UPDATE ni DELETE, pour personne) : créations, modifications,
 * soumissions, approbations, refus, archivages, activations. Il n'a pas de clé étrangère : l'historique d'un modèle supprimé reste lisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('sector_code', 50);
            $table->string('type', 30);
            $table->string('slug', 80);
            $table->string('name', 120);
            $table->string('meta_name', 120)->unique();
            $table->string('category', 12)->default('utility');
            $table->string('status', 20)->default('draft');
            $table->boolean('is_enabled')->default(true);
            $table->text('rejection_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->foreign('sector_code')->references('code')->on('sectors')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unique(['sector_code', 'slug']);
            $table->index(['sector_code', 'type', 'status']);
        });
        DB::statement("ALTER TABLE message_templates ADD CONSTRAINT message_templates_status_check CHECK (status IN ('draft','pending_approval','approved','rejected','archived'))");
        DB::statement("ALTER TABLE message_templates ADD CONSTRAINT message_templates_category_check CHECK (category IN ('utility','marketing'))");

        Schema::create('message_template_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('template_id');
            $table->string('language', 5);
            $table->text('body');
            $table->timestampsTz();

            $table->foreign('template_id')->references('id')->on('message_templates')->cascadeOnDelete();
            $table->unique(['template_id', 'language']);
        });

        Schema::create('message_template_events', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('template_id');
            $table->string('sector_code', 50);
            $table->string('template_name', 120);
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('reason')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->jsonb('snapshot')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['template_id', 'created_at']);
            $table->index(['sector_code', 'created_at']);
        });

        // L'application cliente lit les modèles (envoi des messages) mais n'écrit rien ; le journal lui est fermé.
        DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON message_templates, message_template_translations FROM nova_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON message_templates, message_template_translations TO nova_platform');
        DB::statement('REVOKE ALL ON message_template_events FROM nova_app');
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON message_template_events FROM nova_platform');
        DB::statement('GRANT SELECT, INSERT ON message_template_events TO nova_platform');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_template_events');
        Schema::dropIfExists('message_template_translations');
        Schema::dropIfExists('message_templates');
    }
};
