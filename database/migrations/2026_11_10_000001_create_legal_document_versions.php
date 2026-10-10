<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Documents juridiques versionnés (docs/28) : conditions d'utilisation, politique de confidentialité, accord de sous-traitance (DPA).
 *
 * - `legal_document_versions` : une ligne par version d'un document, avec titre et texte (Markdown) par langue. Une version PUBLIÉE ne
 *   change plus jamais (déclencheur) : un nouveau texte est toujours une nouvelle version. Les brouillons restent modifiables par le
 *   Super Admin. `requires_acceptance` marque une version juridiquement significative : elle impose une nouvelle acceptation.
 * - `user_consents` : l'historique d'acceptation reçoit l'agent utilisateur et la langue affichée comme éléments de preuve ; une
 *   acceptation de conditions, de confidentialité ou de DPA ne peut plus être modifiée après coup, ni enregistrée deux fois pour la même version.
 * La première version (2026-10-01) vient des fichiers resources/legal ; les suivantes se saisissent dans le Super Admin.
 */
return new class extends Migration
{
    private const DOCUMENTS = ['terms' => 'terms-of-use', 'privacy' => 'privacy-policy', 'dpa' => 'dpa'];

    public function up(): void
    {
        Schema::create('legal_document_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('document', 20);
            $table->string('version', 30);
            $table->date('effective_on');
            $table->string('status', 10)->default('draft');
            $table->boolean('requires_acceptance')->default(false);
            $table->jsonb('title')->default('{}');
            $table->jsonb('body')->default('{}');
            $table->jsonb('changes')->default('{}');
            $table->timestampTz('published_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestampsTz();

            $table->unique(['document', 'version']);
            $table->index(['document', 'status', 'effective_on']);
        });
        DB::statement("ALTER TABLE legal_document_versions ADD CONSTRAINT legal_document_versions_document_check CHECK (document IN ('terms', 'privacy', 'dpa'))");
        DB::statement("ALTER TABLE legal_document_versions ADD CONSTRAINT legal_document_versions_status_check CHECK (status IN ('draft', 'published'))");
        DB::statement("ALTER TABLE legal_document_versions ADD CONSTRAINT legal_document_versions_published_check CHECK ((status = 'published') = (published_at IS NOT NULL))");

        // Une version publiée est figée : ni modification ni suppression, même par le Super Admin.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION legal_versions_freeze() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.status = 'published' THEN
                    RAISE EXCEPTION 'Une version publiée d''un document juridique ne peut ni être modifiée ni être supprimée (%).', OLD.version
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER legal_versions_freeze BEFORE UPDATE OR DELETE ON legal_document_versions FOR EACH ROW EXECUTE FUNCTION legal_versions_freeze()');

        // Lecture pour l'application (pages publiques, inscription, réglages) ; écriture réservée à la plateforme.
        DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON legal_document_versions FROM nova_app');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON legal_document_versions TO nova_platform');

        Schema::table('user_consents', function (Blueprint $table): void {
            $table->string('user_agent', 255)->nullable();
            $table->char('locale', 2)->nullable();
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION user_consents_freeze() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.type IN ('terms', 'privacy', 'dpa') THEN
                    RAISE EXCEPTION 'Une acceptation de document juridique ne peut pas être modifiée.'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN NEW;
            END;
            $$
        SQL);
        DB::statement('CREATE TRIGGER user_consents_freeze BEFORE UPDATE ON user_consents FOR EACH ROW EXECUTE FUNCTION user_consents_freeze()');
        DB::statement("CREATE UNIQUE INDEX user_consents_one_per_legal_version ON user_consents (user_id, type, document_version) WHERE type IN ('terms', 'privacy', 'dpa')");

        $this->seedFirstVersions();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS user_consents_one_per_legal_version');
        DB::statement('DROP TRIGGER IF EXISTS user_consents_freeze ON user_consents');
        DB::statement('DROP FUNCTION IF EXISTS user_consents_freeze()');
        Schema::table('user_consents', function (Blueprint $table): void {
            $table->dropColumn(['user_agent', 'locale']);
        });
        DB::statement('DROP TRIGGER IF EXISTS legal_versions_freeze ON legal_document_versions');
        Schema::dropIfExists('legal_document_versions');
        DB::statement('DROP FUNCTION IF EXISTS legal_versions_freeze()');
    }

    /** Première version de chaque document, lue dans resources/legal/{document}/{langue}.md (première ligne « # Titre »). */
    private function seedFirstVersions(): void
    {
        foreach (self::DOCUMENTS as $code => $slug) {
            $titles = [];
            $bodies = [];

            foreach (['fr', 'nl', 'en'] as $locale) {
                $file = resource_path("legal/{$slug}/{$locale}.md");

                if (! is_file($file)) {
                    continue;
                }

                $lines = preg_split('/\R/', trim((string) file_get_contents($file))) ?: [];
                $titles[$locale] = trim(ltrim((string) array_shift($lines), '# '));
                $bodies[$locale] = trim(implode("\n", $lines));
            }

            DB::table('legal_document_versions')->insert([
                'id' => (string) Str::uuid7(),
                'document' => $code,
                'version' => (string) config('nova.legal.first_version', '2026-10-01'),
                'effective_on' => '2026-01-01',
                'status' => 'published',
                'requires_acceptance' => true,
                'title' => json_encode($titles, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'body' => json_encode($bodies, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'changes' => json_encode((object) [], JSON_THROW_ON_ERROR),
                'published_at' => '2026-10-01 00:00:00+00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
