<?php

declare(strict_types=1);

namespace App\Modules\Help\Http\Controllers;

use App\Modules\Help\Models\HelpFeedback;
use App\Modules\Help\Support\HelpArticle;
use App\Modules\Help\Support\HelpCatalog;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Centre d'aide (docs/22) : accueil, catégories, articles, recherche et avis. Réservé aux personnes connectées ; le contenu vient
 * de fichiers Markdown et des articles publiés par le Super Admin (HelpCatalog, docs/30), dans la langue de l'interface.
 */
final class HelpController
{
    public function __construct(private readonly HelpCatalog $catalog, private readonly CurrentTenant $current) {}

    public function index(): Response
    {
        $locale = app()->getLocale();
        $all = $this->catalog->articles($locale);
        $info = $this->catalog->categoryInfo($locale);

        return Inertia::render('Help/Index', [
            'categories' => array_map(fn (string $slug): array => [
                'slug' => $slug,
                'icon' => $info[$slug]['icon'],
                'count' => count($this->catalog->inCategory($locale, $slug)),
            ], $this->catalog->categories()),
            'popular' => array_map($this->card(...), $this->catalog->popular($locale, (int) config('help.popular'))),
            'index' => $this->catalog->searchIndex($locale),
            'total' => count($all),
        ]);
    }

    public function search(Request $request): Response
    {
        return Inertia::render('Help/Search', [
            'q' => mb_substr(trim($request->string('q')->toString()), 0, 100),
            'index' => $this->catalog->searchIndex(app()->getLocale()),
        ]);
    }

    public function category(string $category): Response
    {
        $info = $this->catalog->categoryInfo(app()->getLocale());

        // Une catégorie inconnue, désactivée ou sans nom connu n'existe pas pour le client.
        abort_unless(isset($info[$category]), 404);

        return Inertia::render('Help/Category', [
            'category' => ['slug' => $category, 'icon' => $info[$category]['icon']],
            'articles' => array_map($this->card(...), $this->catalog->inCategory(app()->getLocale(), $category)),
        ]);
    }

    public function show(string $category, string $slug): Response
    {
        $locale = app()->getLocale();
        $article = $this->catalog->find($locale, $category, $slug) ?? abort(404);
        $rendered = $this->catalog->render($article);

        return Inertia::render('Help/Article', [
            'article' => [...$this->card($article), 'updated' => $article->updated, 'html' => $rendered['html'], 'headings' => $rendered['headings']],
            'related' => array_map($this->card(...), $this->catalog->related($locale, $article)),
            'answered' => HelpFeedback::query()->where('user_id', auth()->id())->where('locale', $locale)->where('category', $category)->where('slug', $slug)
                ->where('answered_on', now()->toDateString())->exists(),
        ]);
    }

    /**
     * Avis sur un article : une réponse par personne, article et jour (une deuxième est ignorée sans erreur). Le commentaire
     * n'est lu que par l'équipe Nova.
     */
    public function feedback(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:40'],
            'slug' => ['required', 'string', 'max:80'],
            'helpful' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        $locale = app()->getLocale();

        abort_if($this->catalog->find($locale, $data['category'], $data['slug']) === null, 404);

        try {
            HelpFeedback::query()->create([
                'org_id' => $this->current->organization()?->id,
                'user_id' => auth()->id(),
                'locale' => $locale,
                'category' => $data['category'],
                'slug' => $data['slug'],
                'helpful' => (bool) $data['helpful'],
                'comment' => filled($data['comment'] ?? null) ? trim((string) $data['comment']) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Déjà répondu aujourd'hui : on remercie quand même, sans doublon.
        }

        return back()->with('status', __('ui.help.feedback.thanks'));
    }

    /**
     * @return array{category: string, slug: string, title: string, summary: string}
     */
    private function card(HelpArticle $article): array
    {
        return ['category' => $article->category, 'slug' => $article->slug, 'title' => $article->title, 'summary' => $article->summary];
    }
}
