<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion des FAQ / articles d'aide par le Super Admin (docs/30).
 *
 * Contenu global (aucune donnée d'un établissement, pas d'`org_id`). La confidentialité des brouillons est garantie par la base :
 *  - le rôle client (nova_app) ne lit que ce qui est publié : catégories actives, articles « published », traductions prêtes d'un
 *    article publié (et, en mode « toutes les langues », seulement quand les trois traductions sont prêtes) ;
 *  - il n'a aucun droit sur l'historique des versions ;
 *  - le rôle Super Admin (nova_platform) lit et écrit tout.
 * `platform_admins.support_role` accepte aussi « editor » (éditeur de contenu : FAQ seulement).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('slug', 40)->unique();
            $table->string('icon', 20)->default('rocket');
            $table->integer('position')->default(100);
            $table->boolean('is_active')->default(true);
            $table->jsonb('names')->default('{}');
            $table->jsonb('descriptions')->default('{}');
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE faq_categories ADD CONSTRAINT faq_categories_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");

        Schema::create('faq_articles', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('category_id');
            $table->string('slug', 80);
            $table->string('status', 10)->default('draft');
            $table->string('publication', 10)->default('available');
            $table->integer('position')->default(100);
            $table->boolean('is_popular')->default(false);
            $table->jsonb('tags')->default('[]');
            $table->uuid('author_id')->nullable();
            $table->uuid('last_editor_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('category_id')->references('id')->on('faq_categories')->restrictOnDelete();
            $table->unique(['category_id', 'slug']);
            $table->index(['status', 'category_id', 'position']);
        });
        DB::statement("ALTER TABLE faq_articles ADD CONSTRAINT faq_articles_status_check CHECK (status IN ('draft','published','archived'))");
        DB::statement("ALTER TABLE faq_articles ADD CONSTRAINT faq_articles_publication_check CHECK (publication IN ('all','available'))");
        DB::statement("ALTER TABLE faq_articles ADD CONSTRAINT faq_articles_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");

        Schema::create('faq_translations', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('article_id');
            $table->string('locale', 2);
            $table->string('question', 160);
            $table->string('summary', 300)->default('');
            $table->text('body')->default('');
            $table->boolean('is_ready')->default(false);
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('article_id')->references('id')->on('faq_articles')->cascadeOnDelete();
            $table->unique(['article_id', 'locale']);
        });
        DB::statement("ALTER TABLE faq_translations ADD CONSTRAINT faq_translations_locale_check CHECK (locale IN ('fr','nl','en'))");
        // Une traduction prête a une question, un résumé et un texte.
        DB::statement("ALTER TABLE faq_translations ADD CONSTRAINT faq_translations_ready_check CHECK (NOT is_ready OR (btrim(question) <> '' AND btrim(summary) <> '' AND btrim(body) <> ''))");

        Schema::create('faq_article_related', function (Blueprint $table): void {
            $table->uuid('article_id');
            $table->uuid('related_id');
            $table->integer('position')->default(0);

            $table->primary(['article_id', 'related_id']);
            $table->foreign('article_id')->references('id')->on('faq_articles')->cascadeOnDelete();
            $table->foreign('related_id')->references('id')->on('faq_articles')->cascadeOnDelete();
        });
        DB::statement('ALTER TABLE faq_article_related ADD CONSTRAINT faq_related_self_check CHECK (article_id <> related_id)');

        // Historique des versions d'une traduction : ajout seul, lu par l'équipe seulement.
        Schema::create('faq_translation_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->uuid('article_id');
            $table->string('locale', 2);
            $table->string('question', 160);
            $table->string('summary', 300)->default('');
            $table->text('body')->default('');
            $table->boolean('is_ready')->default(false);
            $table->uuid('edited_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('article_id')->references('id')->on('faq_articles')->cascadeOnDelete();
            $table->index(['article_id', 'locale', 'created_at']);
        });

        $this->security();

        DB::statement('ALTER TABLE platform_admins DROP CONSTRAINT IF EXISTS platform_admins_support_role_check');
        DB::statement("ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_support_role_check CHECK (support_role IS NULL OR support_role IN ('agent','editor'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE platform_admins DROP CONSTRAINT IF EXISTS platform_admins_support_role_check');
        DB::statement("ALTER TABLE platform_admins ADD CONSTRAINT platform_admins_support_role_check CHECK (support_role IS NULL OR support_role IN ('agent'))");

        Schema::dropIfExists('faq_translation_versions');
        Schema::dropIfExists('faq_article_related');
        Schema::dropIfExists('faq_translations');
        Schema::dropIfExists('faq_articles');
        Schema::dropIfExists('faq_categories');
        // Les politiques qui s'en servent disparaissent avec les tables.
        DB::statement('DROP FUNCTION IF EXISTS faq_inactive_categories()');
        DB::statement('DROP FUNCTION IF EXISTS faq_managed_keys()');
        DB::statement('DROP FUNCTION IF EXISTS faq_ready_count(uuid)');
    }

    private function security(): void
    {
        // Nombre de traductions prêtes d'un article. Fonction SECURITY DEFINER : elle lit la table sans repasser par sa propre politique
        // (une politique qui lit sa propre table boucle). Ces tables n'utilisent donc pas FORCE : le propriétaire (migrations, fonctions) n'est pas filtré,
        // alors que les rôles nova_app et nova_platform le sont.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION faq_ready_count(p_article_id uuid) RETURNS integer
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$ SELECT count(*)::integer FROM faq_translations WHERE article_id = p_article_id AND is_ready $$
        SQL);
        DB::statement('GRANT EXECUTE ON FUNCTION faq_ready_count(uuid) TO nova_app, nova_platform');

        // Clés « catégorie / article » de TOUS les articles gérés en base, brouillons et archives compris : le centre d'aide s'en sert pour ne pas
        // ressortir la version fichier d'un article que l'équipe a dépublié ou archivé. Ne renvoie que des identifiants, jamais de texte.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION faq_managed_keys() RETURNS TABLE (category text, slug text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$ SELECT c.slug::text, a.slug::text FROM faq_articles a JOIN faq_categories c ON c.id = a.category_id $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION faq_managed_keys() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION faq_managed_keys() TO nova_app, nova_platform');

        // Codes des catégories désactivées : le rôle client ne les lit pas, mais le centre d'aide doit aussi masquer leurs articles « fichier ».
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION faq_inactive_categories() RETURNS TABLE (slug text)
            LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public
            AS $$ SELECT slug::text FROM faq_categories WHERE NOT is_active $$
        SQL);
        DB::statement('REVOKE ALL ON FUNCTION faq_inactive_categories() FROM PUBLIC');
        DB::statement('GRANT EXECUTE ON FUNCTION faq_inactive_categories() TO nova_app, nova_platform');

        foreach (['faq_categories', 'faq_articles', 'faq_translations', 'faq_article_related', 'faq_translation_versions'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("REVOKE ALL ON {$table} FROM nova_app");
            DB::statement("CREATE POLICY platform_all ON {$table} FOR ALL TO nova_platform USING (true) WITH CHECK (true)");
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO nova_platform");
        }

        // Les versions : ajout seul, y compris pour la plateforme.
        DB::statement('REVOKE UPDATE, DELETE ON faq_translation_versions FROM nova_platform');
        DB::statement('DROP POLICY platform_all ON faq_translation_versions');
        DB::statement('CREATE POLICY platform_read ON faq_translation_versions FOR SELECT TO nova_platform USING (true)');
        DB::statement('CREATE POLICY platform_insert ON faq_translation_versions FOR INSERT TO nova_platform WITH CHECK (true)');
        DB::statement('CREATE TRIGGER faq_versions_append_only BEFORE UPDATE ON faq_translation_versions FOR EACH ROW EXECUTE FUNCTION forbid_modification()');

        // Le client ne lit que le publié.
        DB::statement('GRANT SELECT ON faq_categories, faq_articles, faq_translations, faq_article_related TO nova_app');
        DB::statement('CREATE POLICY client_read ON faq_categories FOR SELECT TO nova_app USING (is_active)');
        DB::statement("CREATE POLICY client_read ON faq_articles FOR SELECT TO nova_app USING (status = 'published')");
        DB::statement(<<<'SQL'
            CREATE POLICY client_read ON faq_translations FOR SELECT TO nova_app USING (
                is_ready AND EXISTS (
                    SELECT 1 FROM faq_articles a
                    WHERE a.id = faq_translations.article_id
                      AND a.status = 'published'
                      AND (a.publication = 'available' OR faq_ready_count(a.id) = 3)
                )
            )
        SQL);
        DB::statement("CREATE POLICY client_read ON faq_article_related FOR SELECT TO nova_app USING (EXISTS (SELECT 1 FROM faq_articles a WHERE a.id = faq_article_related.related_id AND a.status = 'published'))");
    }
};
