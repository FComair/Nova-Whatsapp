<?php

declare(strict_types=1);

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformFaqArticle;
use App\Modules\Admin\Models\PlatformFaqCategory;
use App\Modules\Admin\Services\FaqCategoryManager;
use App\Modules\Admin\Services\FaqManager;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Help\Models\FaqVersion;
use App\Modules\Help\Support\FaqImporter;
use App\Modules\Help\Support\FaqPublishing;
use App\Modules\Help\Support\FaqRefused;
use App\Modules\Help\Support\HelpCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Gestion des FAQ par le Super Admin (docs/30) : modèle, règles de publication, traductions, versions, catégories, intégration au centre
 * d'aide du client (brouillons, archives et langues pas prêtes jamais visibles, jusque dans la base) et journal d'audit.
 */
function ready(string $question, string $body = "## Étapes\n\nOuvrez **Réglages**, puis `Équipe`."): array
{
    return ['question' => $question, 'summary' => 'Un résumé assez long pour les listes.', 'body' => $body, 'is_ready' => true];
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount();
    $this->asOwner(fn () => $this->owner->forceFill(['locale' => 'fr'])->save());

    $this->boss = PlatformAdmin::query()->create(['email' => 'fondateur@rendivoo.test', 'password' => 'mot-de-passe-du-fondateur-2026!']);
    $this->manager = fn (): FaqManager => app(FaqManager::class);
    $this->categories = fn (): FaqCategoryManager => app(FaqCategoryManager::class);
    $this->cat = fn (string $slug = 'novelties'): PlatformFaqCategory => PlatformFaqCategory::query()->where('slug', $slug)->first()
        ?? ($this->categories)()->create($this->boss, ['slug' => $slug, 'names' => ['fr' => 'Nouveautés', 'en' => 'News', 'nl' => 'Nieuws'], 'descriptions' => ['fr' => 'Ce qui change'], 'icon' => 'chat', 'position' => 5]);
    $this->faq = fn (array $overrides = []): PlatformFaqArticle => ($this->manager)()->create($this->boss, [
        'category_id' => ($this->cat)()->id,
        'translations' => ['fr' => ready('Comment inviter un collègue ?'), 'en' => ready('How do I invite a colleague?', "## Steps\n\nOpen **Settings**.")],
        ...$overrides,
    ]);
    $this->fresh = fn (PlatformFaqArticle $article): PlatformFaqArticle => PlatformFaqArticle::query()->findOrFail($article->id);
    $this->audit = fn (string $action) => DB::connection('pgsql_platform')->table('audit_logs')->where('action', $action)->get();
    $this->help = fn () => $this->signedIn($this->owner, $this->org);
    $this->catalog = fn (): HelpCatalog => app(HelpCatalog::class);
});

describe('création', function (): void {
    it('crée un brouillon avec son adresse tirée de la première question, ses traductions et son historique', function (): void {
        $article = ($this->faq)(['tags' => ['  Équipe  ', 'invitation', 'invitation'], 'position' => 3]);

        expect($article->status)->toBe('draft')->and($article->slug)->toBe('comment-inviter-un-collegue')->and($article->tags)->toBe(['équipe', 'invitation'])
            ->and($article->position)->toBe(3)->and($article->author_id)->toBe($this->boss->id)->and($article->last_editor_id)->toBe($this->boss->id)
            ->and($article->published_at)->toBeNull()->and($article->translations)->toHaveCount(2);
        expect(FaqVersion::query()->where('article_id', $article->id)->count())->toBe(2);
        expect(($this->audit)('faq.created'))->toHaveCount(1)->and(($this->audit)('faq.created')->first()->entity_id)->toBe($article->id);
    });

    it('refuse une FAQ sans aucune question, une adresse invalide ou déjà prise', function (): void {
        expect(fn () => ($this->manager)()->create($this->boss, ['category_id' => ($this->cat)()->id, 'translations' => ['fr' => ['question' => '', 'summary' => '', 'body' => '']]]))->toThrow(FaqRefused::class);
        expect(fn () => ($this->faq)(['slug' => 'Adresse Invalide']))->toThrow(FaqRefused::class);

        ($this->faq)(['slug' => 'inviter']);
        expect(fn () => ($this->faq)(['slug' => 'inviter']))->toThrow(FaqRefused::class);
    });

    it('relie des articles sans jamais en lier un à lui-même', function (): void {
        $a = ($this->faq)(['slug' => 'article-a']);
        $b = ($this->faq)(['slug' => 'article-b', 'related' => [$a->id]]);
        ($this->manager)()->update($this->boss, $b, ['related' => [$b->id, $a->id]]);

        expect($b->related()->pluck('faq_articles.id')->all())->toBe([$a->id]);
    });

    it('refuse une traduction prête sans résumé ou sans texte, dans le service comme dans la base', function (): void {
        expect(fn () => ($this->faq)(['translations' => ['fr' => ['question' => 'Question seule', 'summary' => '', 'body' => '', 'is_ready' => true]]]))->toThrow(FaqRefused::class);

        $article = ($this->faq)();
        $row = $article->translations->first();
        expect(fn () => DB::connection('pgsql_platform')->table('faq_translations')->where('id', $row->id)->update(['summary' => '', 'is_ready' => true]))->toThrow(QueryException::class);
    });
});

describe('publication', function (): void {
    it('publie quand les langues obligatoires sont prêtes et dit lesquelles manquent sinon', function (): void {
        $article = ($this->faq)(['translations' => ['fr' => ready('Seulement en français')]]);

        try {
            ($this->manager)()->publish($this->boss, $article);
            $this->fail('La publication aurait dû être refusée.');
        } catch (FaqRefused $refused) {
            expect($refused->reason)->toBe('missing_translations')->and($refused->params['locales'])->toBe('EN');
        }

        ($this->manager)()->update($this->boss, $article, ['translations' => ['en' => ready('Only in English')]]);
        ($this->manager)()->publish($this->boss, ($this->fresh)($article));

        $after = ($this->fresh)($article);
        expect($after->status)->toBe('published')->and($after->published_at)->not->toBeNull();
        expect(($this->audit)('faq.published'))->toHaveCount(1);
        expect(FaqPublishing::completeness($after->load('translations')))->toBe(['fr' => 'ready', 'nl' => 'missing', 'en' => 'ready']);
    });

    it('exige les trois langues en mode « toutes les langues »', function (): void {
        $article = ($this->faq)(['publication' => 'all']);

        expect(fn () => ($this->manager)()->publish($this->boss, $article))->toThrow(FaqRefused::class);

        ($this->manager)()->update($this->boss, $article, ['translations' => ['nl' => ready('Hoe nodig ik een collega uit?')]]);
        ($this->manager)()->publish($this->boss, ($this->fresh)($article));

        expect(($this->fresh)($article)->status)->toBe('published');
    });

    it('ne laisse pas retirer une langue obligatoire sous une FAQ publiée', function (): void {
        $article = ($this->faq)();
        ($this->manager)()->publish($this->boss, $article);

        expect(fn () => ($this->manager)()->update($this->boss, ($this->fresh)($article), ['translations' => ['en' => [...ready('How do I invite a colleague?'), 'is_ready' => false]]]))->toThrow(FaqRefused::class);
        expect(($this->fresh)($article)->translations()->where('locale', 'en')->value('is_ready'))->toBeTrue();
    });

    it('dépublie, archive, restaure ; refuse les états impossibles et la suppression d\'une FAQ publiée', function (): void {
        $article = ($this->faq)();

        expect(fn () => ($this->manager)()->unpublish($this->boss, $article))->toThrow(FaqRefused::class);
        ($this->manager)()->publish($this->boss, $article);
        expect(fn () => ($this->manager)()->publish($this->boss, ($this->fresh)($article)))->toThrow(FaqRefused::class);
        expect(fn () => ($this->manager)()->delete($this->boss, ($this->fresh)($article)))->toThrow(FaqRefused::class);

        ($this->manager)()->unpublish($this->boss, ($this->fresh)($article));
        expect(($this->fresh)($article)->status)->toBe('draft');

        ($this->manager)()->archive($this->boss, ($this->fresh)($article));
        $archived = ($this->fresh)($article);
        expect($archived->status)->toBe('archived')->and($archived->archived_at)->not->toBeNull();
        expect(fn () => ($this->manager)()->archive($this->boss, $archived))->toThrow(FaqRefused::class);

        ($this->manager)()->restore($this->boss, $archived);
        expect(($this->fresh)($article))->status->toBe('draft')->archived_at->toBeNull();
        foreach (['faq.unpublished', 'faq.archived', 'faq.restored'] as $action) {
            expect(($this->audit)($action))->toHaveCount(1);
        }
    });

    it('supprime un brouillon ou une archive et garde sa trace dans le journal', function (): void {
        $article = ($this->faq)(['slug' => 'a-supprimer']);
        ($this->manager)()->delete($this->boss, $article);

        expect(PlatformFaqArticle::query()->whereKey($article->id)->exists())->toBeFalse();
        expect(FaqVersion::query()->where('article_id', $article->id)->count())->toBe(0);
        $log = ($this->audit)('faq.deleted')->first();
        expect($log->entity_id)->toBe($article->id)->and(json_decode($log->diff, true))->toMatchArray(['slug' => 'a-supprimer', 'category' => 'novelties']);
    });
});

describe('traductions et versions', function (): void {
    it('consigne l\'ajout et la mise à jour d\'une traduction, et ne crée une version que si le texte change', function (): void {
        $article = ($this->faq)();
        $before = FaqVersion::query()->where('article_id', $article->id)->count();

        ($this->manager)()->update($this->boss, $article, ['translations' => ['nl' => ready('Hoe nodig ik een collega uit?')]]);
        expect(($this->audit)('faq.translation_added'))->toHaveCount(1);

        ($this->manager)()->update($this->boss, ($this->fresh)($article), ['translations' => ['nl' => ready('Hoe nodig ik een collega uit?', "## Stappen\n\nNieuw.")]]);
        expect(($this->audit)('faq.translation_updated'))->toHaveCount(1);
        expect(FaqVersion::query()->where('article_id', $article->id)->count())->toBe($before + 2);

        ($this->manager)()->update($this->boss, ($this->fresh)($article), ['translations' => ['nl' => ready('Hoe nodig ik een collega uit?', "## Stappen\n\nNieuw.")]]);
        expect(FaqVersion::query()->where('article_id', $article->id)->count())->toBe($before + 2)->and(($this->audit)('faq.translation_updated'))->toHaveCount(1);
    });

    it('rétablit une version antérieure comme nouvelle version, sans réécrire l\'historique', function (): void {
        $article = ($this->faq)();
        ($this->manager)()->update($this->boss, $article, ['translations' => ['fr' => ready('Comment inviter un collègue ?', 'Texte modifié.')]]);
        $first = FaqVersion::query()->where('article_id', $article->id)->where('locale', 'fr')->orderBy('created_at')->firstOrFail();

        ($this->manager)()->restoreVersion($this->boss, ($this->fresh)($article), $first->id);

        expect(($this->fresh)($article)->translations()->where('locale', 'fr')->value('body'))->toContain('Étapes');
        expect(FaqVersion::query()->where('article_id', $article->id)->where('locale', 'fr')->count())->toBe(3);
        expect(fn () => FaqVersion::query()->whereKey($first->id)->update(['body' => 'réécrit']))->toThrow(QueryException::class);
    });

    it('consigne la modification de l\'ordre et des champs de la fiche avec leur avant et après', function (): void {
        $article = ($this->faq)(['position' => 10, 'tags' => ['a']]);
        ($this->manager)()->update($this->boss, $article, ['position' => 2, 'tags' => ['a', 'b'], 'is_popular' => true]);

        $updated = json_decode(($this->audit)('faq.updated')->first()->diff, true);
        expect($updated['position'])->toBe([10, 2])->and($updated['tags'])->toBe([['a'], ['a', 'b']])->and($updated['is_popular'])->toBe([false, true]);
        expect(($this->audit)('faq.order_changed'))->toHaveCount(1);
    });
});

describe('copie, déplacement et actions en lot', function (): void {
    it('duplique en brouillon, traductions à relire, avec une adresse libre', function (): void {
        $article = ($this->faq)(['slug' => 'inviter', 'tags' => ['equipe'], 'is_popular' => true]);
        $copy = ($this->manager)()->duplicate($this->boss, $article);
        $second = ($this->manager)()->duplicate($this->boss, $article);

        expect($copy->slug)->toBe('inviter-copy')->and($second->slug)->toBe('inviter-copy-2')->and($copy->status)->toBe('draft')->and($copy->is_popular)->toBeFalse()
            ->and($copy->tags)->toBe(['equipe'])->and($copy->translations()->where('is_ready', true)->count())->toBe(0)->and($copy->translations()->count())->toBe(2);
        expect(($this->audit)('faq.duplicated'))->toHaveCount(2);
    });

    it('déplace vers une autre catégorie sauf si l\'adresse y existe déjà', function (): void {
        $other = ($this->cat)('guides');
        $a = ($this->faq)(['slug' => 'meme-adresse']);
        ($this->faq)(['slug' => 'meme-adresse', 'category_id' => $other->id]);

        expect(fn () => ($this->manager)()->move($this->boss, $a, $other->id))->toThrow(FaqRefused::class);

        $b = ($this->faq)(['slug' => 'autre-adresse']);
        ($this->manager)()->move($this->boss, $b, $other->id);
        expect(($this->fresh)($b)->category_id)->toBe($other->id);
    });

    it('applique une action à plusieurs FAQ et compte celles qui la refusent', function (): void {
        $ok = ($this->faq)(['slug' => 'prete']);
        $draft = ($this->faq)(['slug' => 'incomplete', 'translations' => ['fr' => ready('Seulement en français')]]);

        $result = ($this->manager)()->bulk($this->boss, [$ok->id, $draft->id, '00000000-0000-0000-0000-000000000000'], 'publish', fn (PlatformFaqArticle $a) => ($this->manager)()->publish($this->boss, $a));

        expect($result)->toBe(['done' => 1, 'skipped' => 2]);
        expect(($this->fresh)($ok)->status)->toBe('published')->and(($this->fresh)($draft)->status)->toBe('draft');
        expect(($this->audit)('faq.bulk'))->toHaveCount(1);
    });
});

describe('catégories', function (): void {
    it('crée, modifie, active et désactive une catégorie, avec audit', function (): void {
        $category = ($this->cat)();
        expect($category->name('nl'))->toBe('Nieuws')->and($category->description('fr'))->toBe('Ce qui change')->and(($this->audit)('faq_category.created'))->toHaveCount(1);

        ($this->categories)()->update($this->boss, $category, ['names' => ['fr' => 'Nouveautés 2026'], 'position' => 1, 'is_active' => false, 'icon' => 'shield']);
        $after = PlatformFaqCategory::query()->findOrFail($category->id);

        expect($after->name('fr'))->toBe('Nouveautés 2026')->and($after->name('nl'))->toBe('Nouveautés 2026')->and($after->is_active)->toBeFalse()->and($after->icon)->toBe('shield');
        expect(($this->audit)('faq_category.updated'))->toHaveCount(1)->and(($this->audit)('faq_category.order_changed'))->toHaveCount(1);
    });

    it('refuse une adresse réservée, invalide ou prise, et une catégorie sans nom', function (): void {
        foreach (['contact', 'recherche', 'Mauvaise Adresse'] as $slug) {
            expect(fn () => ($this->categories)()->create($this->boss, ['slug' => $slug, 'names' => ['fr' => 'X']]))->toThrow(FaqRefused::class);
        }

        ($this->cat)();
        expect(fn () => ($this->categories)()->create($this->boss, ['slug' => 'novelties', 'names' => ['fr' => 'X']]))->toThrow(FaqRefused::class);
        expect(fn () => ($this->categories)()->create($this->boss, ['slug' => 'sans-nom', 'names' => []]))->toThrow(FaqRefused::class);
    });

    it('ne supprime pas une catégorie qui contient des articles, sauf réaffectation explicite', function (): void {
        $category = ($this->cat)();
        $other = ($this->cat)('guides');
        $article = ($this->faq)();

        try {
            ($this->categories)()->delete($this->boss, $category);
            $this->fail('La suppression aurait dû être refusée.');
        } catch (FaqRefused $refused) {
            expect($refused->reason)->toBe('category_not_empty')->and($refused->params['count'])->toBe(1);
        }

        ($this->categories)()->reassignAndDelete($this->boss, $category, $other);

        expect(PlatformFaqCategory::query()->whereKey($category->id)->exists())->toBeFalse()->and(($this->fresh)($article)->category_id)->toBe($other->id);
        expect(($this->audit)('faq_category.articles_moved'))->toHaveCount(1)->and(($this->audit)('faq_category.deleted'))->toHaveCount(1);
    });

    it('refuse la réaffectation quand une adresse existe déjà dans la catégorie d\'accueil, sans rien changer', function (): void {
        $category = ($this->cat)();
        $other = ($this->cat)('guides');
        ($this->faq)(['slug' => 'doublon']);
        ($this->faq)(['slug' => 'doublon', 'category_id' => $other->id]);

        expect(fn () => ($this->categories)()->reassignAndDelete($this->boss, $category, $other))->toThrow(FaqRefused::class);
        expect(PlatformFaqCategory::query()->whereKey($category->id)->exists())->toBeTrue()->and(PlatformFaqArticle::query()->where('category_id', $category->id)->count())->toBe(1);
    });

    it('ne supprime pas une catégorie livrée avec l\'application : on la désactive', function (): void {
        app(FaqImporter::class)->import();
        $builtin = PlatformFaqCategory::query()->where('slug', 'billing')->firstOrFail();
        PlatformFaqArticle::query()->where('category_id', $builtin->id)->delete();

        expect(fn () => ($this->categories)()->delete($this->boss, $builtin))->toThrow(FaqRefused::class);
    });
});

describe('centre d\'aide du client', function (): void {
    it('montre une FAQ publiée dans sa catégorie, avec la recherche, les étiquettes et les articles liés, dans la langue du client', function (): void {
        $related = ($this->faq)(['slug' => 'liee', 'translations' => ['fr' => ready('Article lié'), 'en' => ready('Linked article')]]);
        $article = ($this->faq)(['slug' => 'inviter', 'tags' => ['équipe'], 'position' => 1, 'related' => [$related->id]]);
        ($this->manager)()->publish($this->boss, $article);
        ($this->manager)()->publish($this->boss, $related);

        ($this->help)()->get('/aide/novelties')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Help/Category')->has('articles', 2)->where('articles.0.title', 'Comment inviter un collègue ?'));
        ($this->help)()->get('/aide/novelties/inviter')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Help/Article')->where('article.title', 'Comment inviter un collègue ?')->where('article.html', fn (string $html) => str_contains($html, '<strong>Réglages</strong>') && str_contains($html, '<code>Équipe</code>'))
            ->has('related', 1)->where('related.0.title', 'Article lié')->where('helpCategories.novelties.name', 'Nouveautés'));
        ($this->help)()->get('/aide/recherche?q=x')->assertInertia(fn (Assert $page) => $page->where('index', fn ($index) => collect($index)->contains(fn (array $entry): bool => $entry['slug'] === 'inviter' && $entry['tags'] === ['équipe'] && str_contains($entry['text'], 'Réglages'))));
        ($this->help)()->get('/aide')->assertInertia(fn (Assert $page) => $page->where('categories', fn ($categories) => collect($categories)->contains(fn (array $c): bool => $c['slug'] === 'novelties' && $c['count'] === 2 && $c['icon'] === 'chat')));

        $this->asOwner(fn () => $this->owner->forceFill(['locale' => 'en'])->save());
        ($this->help)()->get('/aide/novelties/inviter')->assertInertia(fn (Assert $page) => $page->where('article.title', 'How do I invite a colleague?')->where('helpCategories.novelties.name', 'News'));
    });

    it('ne montre jamais un brouillon, une archive, une langue pas prête ni une langue absente', function (): void {
        $article = ($this->faq)(['slug' => 'secrete']);

        ($this->help)()->get('/aide/novelties/secrete')->assertNotFound();

        ($this->manager)()->publish($this->boss, $article);
        ($this->help)()->get('/aide/novelties/secrete')->assertOk();

        // Néerlandais absent : un client néerlandophone ne voit pas la FAQ (jamais de texte d'une autre langue).
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => 'nl'])->save());
        ($this->help)()->get('/aide/novelties/secrete')->assertNotFound();

        $this->asOwner(fn () => $this->owner->forceFill(['locale' => 'fr'])->save());
        ($this->manager)()->unpublish($this->boss, ($this->fresh)($article));
        ($this->help)()->get('/aide/novelties/secrete')->assertNotFound();

        ($this->manager)()->publish($this->boss, ($this->fresh)($article));
        ($this->manager)()->archive($this->boss, ($this->fresh)($article));
        ($this->help)()->get('/aide/novelties/secrete')->assertNotFound();
        expect(collect(($this->catalog)()->searchIndex('fr'))->pluck('slug')->all())->not->toContain('secrete');
    });

    it('cache une langue publiée mais pas prête, et le mode « toutes les langues » tant que les trois ne sont pas prêtes (règle de la base)', function (): void {
        $article = ($this->faq)(['slug' => 'partielle']);
        ($this->manager)()->publish($this->boss, $article);
        ($this->manager)()->update($this->boss, ($this->fresh)($article), ['translations' => ['nl' => [...ready('Hoe nodig ik een collega uit?'), 'is_ready' => false]]]);

        $this->asOwner(fn () => $this->owner->forceFill(['locale' => 'nl'])->save());
        ($this->help)()->get('/aide/novelties/partielle')->assertNotFound();

        // Contournement volontaire de l'application : la base refuse quand même de montrer l'article.
        DB::connection('pgsql_platform')->table('faq_articles')->where('id', $article->id)->update(['publication' => 'all']);
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => 'fr'])->save());
        ($this->catalog)()->flush();
        ($this->help)()->get('/aide/novelties/partielle')->assertNotFound();
    });

    it('garde les brouillons, les versions et les tables de gestion hors de portée du rôle client, dans la base', function (): void {
        $article = ($this->faq)(['slug' => 'brouillon']);

        $asApp = fn (string $sql): int => (int) DB::select($sql)[0]->n;
        expect($asApp('select count(*) as n from faq_articles'))->toBe(0)->and($asApp('select count(*) as n from faq_translations'))->toBe(0);
        expect(fn () => DB::select('select count(*) from faq_translation_versions'))->toThrow(QueryException::class);
        expect(fn () => DB::table('faq_articles')->where('id', $article->id)->update(['status' => 'published']))->toThrow(QueryException::class);
        expect(fn () => DB::table('faq_categories')->insert(['slug' => 'pirate', 'names' => '{}']))->toThrow(QueryException::class);

        ($this->manager)()->publish($this->boss, $article);
        expect($asApp('select count(*) as n from faq_articles'))->toBe(1)->and($asApp('select count(*) as n from faq_translations'))->toBe(2);
    });

    it('masque une catégorie désactivée et ses articles, et répond 404', function (): void {
        $article = ($this->faq)(['slug' => 'visible']);
        ($this->manager)()->publish($this->boss, $article);
        ($this->help)()->get('/aide/novelties')->assertOk();

        ($this->categories)()->update($this->boss, ($this->cat)(), ['is_active' => false]);

        ($this->help)()->get('/aide/novelties')->assertNotFound();
        ($this->help)()->get('/aide/novelties/visible')->assertNotFound();
        expect(array_keys(($this->catalog)()->categoryInfo('fr')))->not->toContain('novelties');

        ($this->categories)()->update($this->boss, ($this->cat)(), ['is_active' => true]);
        ($this->help)()->get('/aide/novelties')->assertOk();
    });

    it('applique les noms et descriptions choisis par le Super Admin aux catégories livrées, et masque une catégorie livrée désactivée', function (): void {
        app(FaqImporter::class)->import();
        $billing = PlatformFaqCategory::query()->where('slug', 'billing')->firstOrFail();
        ($this->categories)()->update($this->boss, $billing, ['names' => ['fr' => 'Facturation & paiement']]);

        expect(($this->catalog)()->categoryInfo('fr')['billing']['name'])->toBe('Facturation & paiement');

        ($this->categories)()->update($this->boss, $billing, ['is_active' => false]);
        expect(array_keys(($this->catalog)()->categoryInfo('fr')))->not->toContain('billing');
        ($this->help)()->get('/aide/billing')->assertNotFound();
        ($this->help)()->get('/aide/billing/plans-invoices')->assertNotFound();
    });

    it('enregistre l\'avis « utile » sur une FAQ de la base', function (): void {
        $article = ($this->faq)(['slug' => 'utile']);
        ($this->manager)()->publish($this->boss, $article);

        ($this->help)()->post('/aide/retour', ['category' => 'novelties', 'slug' => 'utile', 'helpful' => true])->assertRedirect();
        ($this->help)()->post('/aide/retour', ['category' => 'novelties', 'slug' => 'brouillon-inconnu', 'helpful' => true])->assertNotFound();

        expect($this->asOwner(fn () => DB::table('help_article_feedback')->where('category', 'novelties')->where('slug', 'utile')->count()))->toBe(1);
    });

    it('garde les adresses du centre d\'aide : une catégorie ne peut pas prendre celle d\'une page existante', function (): void {
        ($this->help)()->get('/aide/contact')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Help/Contact'));
        ($this->help)()->get('/aide/tickets')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Help/Tickets'));
    });
});

describe('import des articles livrés en fichiers', function (): void {
    it('importe les catégories et les articles, publiés et prêts, sans rien écraser, et le client voit la même chose', function (): void {
        $filesBefore = array_keys(($this->catalog)()->articles('fr'));

        $result = app(FaqImporter::class)->import();
        expect($result)->toBe(['categories' => 8, 'articles' => 24, 'skipped' => 0]);
        expect(app(FaqImporter::class)->import())->toBe(['categories' => 0, 'articles' => 0, 'skipped' => 24]);

        foreach (['fr', 'nl', 'en'] as $locale) {
            expect(array_keys(($this->catalog)()->articles($locale)))->toEqual($filesBefore);
        }

        $article = PlatformFaqArticle::query()->whereHas('category', fn ($q) => $q->where('slug', 'billing'))->where('slug', 'plans-invoices')->firstOrFail();
        expect($article->status)->toBe('published')->and($article->publication)->toBe('all')->and($article->translations()->where('is_ready', true)->count())->toBe(3);
        expect($article->related()->count())->toBeGreaterThan(0);
    });

    it('retire vraiment du centre d\'aide un article importé qu\'on dépublie ou archive', function (): void {
        app(FaqImporter::class)->import();
        $article = PlatformFaqArticle::query()->whereHas('category', fn ($q) => $q->where('slug', 'billing'))->where('slug', 'plans-invoices')->firstOrFail();

        expect(array_keys(($this->catalog)()->articles('fr')))->toContain('billing/plans-invoices');

        ($this->manager)()->unpublish($this->boss, $article);
        expect(array_keys(($this->catalog)()->articles('fr')))->not->toContain('billing/plans-invoices');
        ($this->help)()->get('/aide/billing/plans-invoices')->assertNotFound();

        ($this->manager)()->publish($this->boss, ($this->fresh)($article));
        expect(array_keys(($this->catalog)()->articles('fr')))->toContain('billing/plans-invoices');
    });

    it('montre un texte modifié par le Super Admin à la place du fichier', function (): void {
        app(FaqImporter::class)->import();
        $article = PlatformFaqArticle::query()->whereHas('category', fn ($q) => $q->where('slug', 'billing'))->where('slug', 'plans-invoices')->firstOrFail();
        ($this->manager)()->update($this->boss, $article, ['translations' => ['fr' => ready('Abonnement et factures (corrigé)', 'Texte corrigé par l\'équipe.')]]);

        ($this->help)()->get('/aide/billing/plans-invoices')->assertInertia(fn (Assert $page) => $page->where('article.title', 'Abonnement et factures (corrigé)')->where('article.html', fn (string $html) => str_contains($html, 'Texte corrigé')));
    });
});

describe('droits', function (): void {
    it('distingue Super Admin, éditeur de contenu et agent de support', function (): void {
        $editor = PlatformAdmin::query()->create(['email' => 'editeur@rendivoo.test', 'password' => 'mot-de-passe-de-lediteur-2026!']);
        $editor->forceFill(['support_role' => PlatformAdmin::SUPPORT_EDITOR])->save();
        $agent = PlatformAdmin::query()->create(['email' => 'agent@rendivoo.test', 'password' => 'mot-de-passe-de-lagent-2026!']);
        $agent->forceFill(['support_role' => PlatformAdmin::SUPPORT_AGENT])->save();
        $access = fn (PlatformAdmin $admin): array => [SupportAccess::canManageFaq($admin), SupportAccess::canUseTickets($admin), SupportAccess::isRestricted($admin)];

        expect($access($this->boss))->toBe([true, true, false])->and($access($editor))->toBe([true, false, true])->and($access($agent))->toBe([false, true, true]);
        expect(SupportAccess::assignees())->not->toHaveKey($editor->id)->toHaveKey($agent->id)->toHaveKey($this->boss->id);
        expect(fn () => DB::connection('pgsql_platform')->table('platform_admins')->where('id', $agent->id)->update(['support_role' => 'pirate']))->toThrow(QueryException::class);
    });
});

describe('modèle de données', function (): void {
    it('refuse les états, modes, langues et adresses hors liste, et empêche la suppression d\'une catégorie utilisée', function (): void {
        $article = ($this->faq)();
        $db = fn () => DB::connection('pgsql_platform');

        expect(fn () => $db()->table('faq_articles')->where('id', $article->id)->update(['status' => 'inconnu']))->toThrow(QueryException::class);
        expect(fn () => $db()->table('faq_articles')->where('id', $article->id)->update(['publication' => 'peut-etre']))->toThrow(QueryException::class);
        expect(fn () => $db()->table('faq_articles')->where('id', $article->id)->update(['slug' => 'Adresse Invalide']))->toThrow(QueryException::class);
        expect(fn () => $db()->table('faq_translations')->insert(['article_id' => $article->id, 'locale' => 'de', 'question' => 'Frage']))->toThrow(QueryException::class);
        expect(fn () => $db()->table('faq_categories')->where('id', $article->category_id)->delete())->toThrow(QueryException::class);
        expect(fn () => $db()->table('faq_article_related')->insert(['article_id' => $article->id, 'related_id' => $article->id]))->toThrow(QueryException::class);
    });
});
