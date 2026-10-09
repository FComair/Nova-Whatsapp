<?php

declare(strict_types=1);

use App\Modules\Help\Models\HelpFeedback;
use App\Modules\Help\Support\HelpCatalog;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\OrgMember;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;

uses(AuthScenarios::class);

/**
 * Centre d'aide : contenu (trois langues alignées, liens valides), rendu sûr, pages et avis.
 */
function catalog(): HelpCatalog
{
    return app(HelpCatalog::class);
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $this->org] = $this->activatedAccount();
    $this->help = fn () => $this->signedIn($this->owner, $this->org);
});

describe('contenu', function (): void {
    it('a des articles dans chaque catégorie et dans les trois langues, avec les mêmes références', function (): void {
        $keys = fn (string $locale): array => array_keys(catalog()->articles($locale));

        foreach (['fr', 'en', 'nl'] as $locale) {
            expect($keys($locale))->not->toBeEmpty();

            foreach (catalog()->categories() as $category) {
                expect(catalog()->inCategory($locale, $category))->not->toBeEmpty("{$locale} / {$category}");
            }
        }

        expect($keys('en'))->toEqual($keys('fr'))->and($keys('nl'))->toEqual($keys('fr'));
    });

    it('donne à chaque article un titre, un résumé, des étiquettes, une date et un corps', function (string $locale): void {
        foreach (catalog()->articles($locale) as $key => $article) {
            expect($article->title)->not->toBe('', $key)
                ->and(mb_strlen($article->summary))->toBeGreaterThan(20)
                ->and($article->tags)->not->toBeEmpty()
                ->and($article->updated)->toMatch('/^\d{4}-\d{2}-\d{2}$/')
                ->and(mb_strlen($article->body))->toBeGreaterThan(200);
        }
    })->with(['fr', 'en', 'nl']);

    it('ne renvoie que vers des articles qui existent, dans chaque langue', function (string $locale): void {
        foreach (catalog()->articles($locale) as $article) {
            expect($article->related)->not->toBeEmpty($article->key());

            foreach ($article->related as $target) {
                expect(array_key_exists($target, catalog()->articles($locale)))->toBeTrue("{$article->key()} -> {$target}");
            }
        }
    })->with(['fr', 'en', 'nl']);

    it('marque les mêmes articles populaires dans les trois langues, au moins le nombre affiché', function (): void {
        $popular = fn (string $locale): array => array_map(fn ($article): string => $article->key(), catalog()->popular($locale, 99));

        expect($popular('fr'))->toHaveCount((int) config('help.popular'))->and($popular('en'))->toEqual($popular('fr'))->and($popular('nl'))->toEqual($popular('fr'));
    });

    it('retire le HTML brut et les liens dangereux, et ancre les titres de niveau 2', function (): void {
        $dir = sys_get_temp_dir().'/nova-help-'.uniqid();
        File::ensureDirectoryExists($dir.'/fr/getting-started');
        File::put($dir.'/fr/getting-started/test.md', "---\ntitle: Test\nsummary: Un résumé de test assez long\ntags: a\n---\n## Première étape\n\n<script>alert(1)</script>\n\n[mal](javascript:alert(1)) et [bien](/aide)\n\n## Deuxième\n");

        $local = new HelpCatalog($dir);
        $rendered = $local->render($local->find('fr', 'getting-started', 'test'));

        expect($rendered['html'])->not->toContain('<script')->not->toContain('javascript:')->toContain('href="/aide"')
            ->and($rendered['headings'])->toBe([['id' => 'premiere-etape', 'text' => 'Première étape'], ['id' => 'deuxieme', 'text' => 'Deuxième']])
            ->and($rendered['html'])->toContain('<h2 id="premiere-etape">');

        File::deleteDirectory($dir);
    });
});

describe('pages', function (): void {
    it('exige d\'être connecté', function (): void {
        $this->get('/aide')->assertRedirect('/connexion');
        $this->get('/aide/getting-started/first-steps')->assertRedirect('/connexion');
    });

    it('affiche l\'accueil avec les catégories, les articles populaires et l\'index de recherche', function (): void {
        ($this->help)()->get('/aide')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Help/Index')
            ->has('categories', 8)
            ->has('popular', 6)
            ->has('index', count(catalog()->articles('fr')))
            ->where('categories.0.slug', 'getting-started'));
    });

    it('affiche chaque article (HTML rendu, titres, articles liés)', function (): void {
        foreach (catalog()->articles('fr') as $article) {
            ($this->help)()->get("/aide/{$article->category}/{$article->slug}")->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Help/Article')
                ->where('article.title', $article->title)
                ->has('article.html')
                ->has('related'));
        }
    });

    it('affiche les articles dans la langue de l\'utilisateur', function (): void {
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => 'en'])->save());

        ($this->help)()->get('/aide/getting-started/first-steps')->assertInertia(fn (Assert $page) => $page->where('article.title', 'Set up Nova in a few steps'));
    });

    it('affiche une catégorie, la recherche et répond 404 aux adresses inconnues', function (): void {
        ($this->help)()->get('/aide/customers')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Help/Category')->has('articles', 3));
        ($this->help)()->get('/aide/recherche?q=t%C3%A9l%C3%A9phone')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Help/Search')->where('q', 'téléphone')->has('index'));
        ($this->help)()->get('/aide/inconnue')->assertNotFound();
        ($this->help)()->get('/aide/customers/inconnu')->assertNotFound();
    });

    it('est accessible à tous les rôles', function (): void {
        $this->asOwner(function (): void {
            $user = User::factory()->create(['email' => 'recep@example.com']);
            OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => 'receptionist']);
            $this->receptionist = $user;
        });

        $this->signedIn($this->receptionist, $this->org)->get('/aide')->assertOk();
    });
});

describe('avis', function (): void {
    it('enregistre un avis rattaché à l\'établissement et à la personne', function (): void {
        ($this->help)()->post('/aide/retour', ['category' => 'customers', 'slug' => 'find-customers', 'helpful' => false, 'comment' => '  Il manque les exports.  '])->assertRedirect();

        $row = $this->asOwner(fn () => HelpFeedback::query()->firstOrFail());
        expect($row->helpful)->toBeFalse()->and($row->comment)->toBe('Il manque les exports.')->and($row->org_id)->toBe($this->org->id)->and($row->user_id)->toBe($this->owner->id)->and($row->locale)->toBe('fr');
    });

    it('ignore une deuxième réponse le même jour sans erreur', function (): void {
        ($this->help)()->post('/aide/retour', ['category' => 'customers', 'slug' => 'find-customers', 'helpful' => true])->assertSessionHasNoErrors();
        ($this->help)()->post('/aide/retour', ['category' => 'customers', 'slug' => 'find-customers', 'helpful' => false])->assertSessionHasNoErrors();

        expect($this->asOwner(fn () => HelpFeedback::query()->count()))->toBe(1);
        ($this->help)()->get('/aide/customers/find-customers')->assertInertia(fn (Assert $page) => $page->where('answered', true));
    });

    it('valide la demande et refuse un article inconnu', function (): void {
        ($this->help)()->post('/aide/retour', ['category' => 'customers', 'slug' => 'find-customers'])->assertSessionHasErrors('helpful');
        ($this->help)()->post('/aide/retour', ['category' => 'customers', 'slug' => 'find-customers', 'helpful' => true, 'comment' => str_repeat('a', 501)])->assertSessionHasErrors('comment');
        ($this->help)()->post('/aide/retour', ['category' => 'customers', 'slug' => 'inconnu', 'helpful' => true])->assertNotFound();
    });

    it('est un journal en ajout seul', function (): void {
        ($this->help)()->post('/aide/retour', ['category' => 'customers', 'slug' => 'find-customers', 'helpful' => true]);

        expect(fn () => $this->asOwner(fn () => DB::statement('update help_article_feedback set helpful = false')))->toThrow(QueryException::class);
        expect(fn () => $this->asOwner(fn () => DB::statement('delete from help_article_feedback')))->toThrow(QueryException::class);
    });
});
