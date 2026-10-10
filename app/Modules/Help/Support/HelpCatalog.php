<?php

declare(strict_types=1);

namespace App\Modules\Help\Support;

use App\Modules\Help\Models\FaqArticle;
use App\Modules\Help\Models\FaqCategory;
use App\Modules\Help\Models\FaqTranslation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lit les articles d'aide et prépare leur affichage. Deux sources, fusionnées par clé « catégorie/article » :
 *
 *  1. des fichiers Markdown (resources/help/{langue}/{catégorie}/{article}.md), livrés avec l'application ;
 *  2. les articles gérés par le Super Admin en base (docs/30). Dès qu'une clé existe en base — publiée, brouillon ou archivée —, la version
 *     fichier est ignorée : l'équipe garde la main (dépublier un article le retire vraiment).
 *
 * Le client ne voit que le publié : la base filtre (états, traductions prêtes, mode « toutes les langues », catégories actives).
 *
 * Chaque fichier commence par un en-tête simple entre deux lignes « --- » : title, summary, tags (séparés par des virgules),
 * related (« catégorie/article » séparés par des virgules), popular (true), order (nombre), updated (AAAA-MM-JJ).
 * Le corps est du Markdown ; le HTML brut est retiré au rendu.
 */
final class HelpCatalog
{
    /** @var array<string, array<string, HelpArticle>> */
    private array $cache = [];

    /** @var array<string, FaqCategory>|null */
    private ?array $dbCategories = null;

    /** @var array<string, bool>|null */
    private ?array $managedKeys = null;

    public function __construct(private readonly string $path) {}

    /** Oublie ce qui a été lu : à appeler après une modification de contenu dans la même requête. */
    public function flush(): void
    {
        $this->cache = [];
        $this->dbCategories = null;
        $this->managedKeys = null;
    }

    /**
     * Catégories actives dans l'ordre d'affichage : celles du fichier de configuration, plus celles créées en base ; la base (position,
     * activation) l'emporte.
     *
     * @return list<string>
     */
    public function categories(): array
    {
        $db = $this->dbCategories();
        $position = [];
        $index = 0;

        foreach (array_keys((array) config('help.categories')) as $slug) {
            $index++;
            $position[$slug] = isset($db[$slug]) ? $db[$slug]->position : $index * 10;
        }

        foreach ($db as $slug => $category) {
            $position[$slug] = $category->position;
        }

        $slugs = array_keys(array_filter($position, fn (int $value, string $slug): bool => ! isset($db[$slug]) || $db[$slug]->is_active, ARRAY_FILTER_USE_BOTH));
        usort($slugs, fn (string $a, string $b): int => [$position[$a], $a] <=> [$position[$b], $b]);

        return $slugs;
    }

    /**
     * Nom, description et icône de chaque catégorie dans la langue : la base d'abord, sinon les textes du fichier de langue.
     *
     * @return array<string, array{name: string, description: string, icon: string}>
     */
    public function categoryInfo(string $locale): array
    {
        $db = $this->dbCategories();
        $info = [];

        foreach ($this->categories() as $slug) {
            $row = $db[$slug] ?? null;
            $info[$slug] = [
                'name' => ($row !== null && filled($row->names[$locale] ?? null)) ? (string) $row->names[$locale] : (string) __("ui.help.categories.{$slug}.name", [], $locale),
                'description' => ($row !== null && filled($row->descriptions[$locale] ?? null)) ? (string) $row->descriptions[$locale] : (string) __("ui.help.categories.{$slug}.description", [], $locale),
                'icon' => $row !== null ? $row->icon : (string) (config("help.categories.{$slug}") ?? 'rocket'),
            ];

            // Texte absent partout : on ne montre jamais une clé de traduction brute.
            foreach (['name', 'description'] as $field) {
                if (str_starts_with($info[$slug][$field], 'ui.help.categories.')) {
                    $info[$slug][$field] = $field === 'name' ? $slug : '';
                }
            }
        }

        return $info;
    }

    /** @return array<string, HelpArticle> clé « catégorie/article », triée par catégorie puis ordre puis titre */
    public function articles(string $locale): array
    {
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $articles = [];
        $order = array_flip($this->categories());
        $managed = $this->managedKeys();

        foreach ($this->fileArticles($locale) as $key => $article) {
            if (isset($order[$article->category]) && ! isset($managed[$key])) {
                $articles[$key] = $article;
            }
        }

        foreach ($this->databaseArticles($locale) as $article) {
            if (isset($order[$article->category])) {
                $articles[$article->key()] = $article;
            }
        }

        uasort($articles, fn (HelpArticle $a, HelpArticle $b): int => [$order[$a->category], $a->order, $a->title] <=> [$order[$b->category], $b->order, $b->title]);

        return $this->cache[$locale] = $articles;
    }

    /**
     * Articles des fichiers seuls (sans la base, sans filtre) : sert à l'import dans la base.
     *
     * @return array<string, HelpArticle>
     */
    public function fileArticles(string $locale): array
    {
        $articles = [];

        foreach (glob($this->path.'/'.$locale.'/*/*.md') ?: [] as $file) {
            $article = $this->parse($file);

            if ($article !== null) {
                $articles[$article->key()] = $article;
            }
        }

        return $articles;
    }

    public function find(string $locale, string $category, string $slug): ?HelpArticle
    {
        return $this->articles($locale)[$category.'/'.$slug] ?? null;
    }

    /**
     * @return list<HelpArticle>
     */
    public function inCategory(string $locale, string $category): array
    {
        return array_values(array_filter($this->articles($locale), fn (HelpArticle $article): bool => $article->category === $category));
    }

    /**
     * @return list<HelpArticle>
     */
    public function popular(string $locale, int $limit): array
    {
        return array_slice(array_values(array_filter($this->articles($locale), fn (HelpArticle $article): bool => $article->popular)), 0, $limit);
    }

    /**
     * Articles liés qui existent vraiment dans cette langue.
     *
     * @return list<HelpArticle>
     */
    public function related(string $locale, HelpArticle $article): array
    {
        $all = $this->articles($locale);

        return array_values(array_filter(array_map(fn (string $key): ?HelpArticle => $all[$key] ?? null, $article->related)));
    }

    /**
     * Texte brut de l'article pour la recherche (titre, résumé, étiquettes et corps sans mise en forme).
     *
     * @return list<array{category: string, slug: string, title: string, summary: string, tags: list<string>, text: string}>
     */
    public function searchIndex(string $locale): array
    {
        return array_values(array_map(fn (HelpArticle $article): array => [
            'category' => $article->category,
            'slug' => $article->slug,
            'title' => $article->title,
            'summary' => $article->summary,
            'tags' => $article->tags,
            'text' => Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($this->html($article->body)))), 3000, ''),
        ], $this->articles($locale)));
    }

    /**
     * Corps rendu en HTML (le HTML brut du fichier est retiré, les liens dangereux refusés) et ses titres de niveau 2,
     * qui reçoivent une ancre pour la liste « Dans cet article ».
     *
     * @return array{html: string, headings: list<array{id: string, text: string}>}
     */
    public function render(HelpArticle $article): array
    {
        return $this->renderMarkdown($article->body);
    }

    /**
     * Même rendu pour un texte quelconque : l'aperçu du Super Admin montre exactement ce que verra le client.
     *
     * @return array{html: string, headings: list<array{id: string, text: string}>}
     */
    public function renderMarkdown(string $markdown): array
    {
        $headings = [];
        $html = (string) preg_replace_callback('/<h2>(.*?)<\/h2>/s', function (array $match) use (&$headings): string {
            $text = trim(html_entity_decode(strip_tags($match[1])));
            $id = Str::slug($text) ?: 'section-'.(count($headings) + 1);
            $headings[] = ['id' => $id, 'text' => $text];

            return '<h2 id="'.e($id).'">'.$match[1].'</h2>';
        }, $this->html($markdown));

        return ['html' => $html, 'headings' => $headings];
    }

    private function html(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }

    /**
     * @return array<string, FaqCategory> par code (lecture avec le rôle courant : le client ne reçoit que les catégories actives)
     */
    private function dbCategories(): array
    {
        if ($this->dbCategories !== null) {
            return $this->dbCategories;
        }

        // Le rôle client ne lit que les catégories actives ; les inactives viennent d'une fonction qui ne renvoie que leur code.
        $all = FaqCategory::query()->get()->keyBy('slug')->all();

        foreach (DB::select('select slug from faq_inactive_categories()') as $row) {
            $all[$row->slug] ??= new FaqCategory(['slug' => $row->slug, 'is_active' => false, 'position' => 100, 'icon' => 'rocket', 'names' => [], 'descriptions' => []]);
        }

        return $this->dbCategories = $all;
    }

    /** @return array<string, bool> « catégorie/article » de tous les articles gérés en base, quel que soit leur état */
    private function managedKeys(): array
    {
        if ($this->managedKeys !== null) {
            return $this->managedKeys;
        }

        $keys = [];

        foreach (DB::select('select category, slug from faq_managed_keys()') as $row) {
            $keys[$row->category.'/'.$row->slug] = true;
        }

        return $this->managedKeys = $keys;
    }

    /**
     * Articles publiés en base dans cette langue (la base ne renvoie que le publié et les traductions prêtes).
     *
     * @return list<HelpArticle>
     */
    private function databaseArticles(string $locale): array
    {
        /** @var Collection<int, FaqArticle> $rows */
        $rows = FaqArticle::query()->with(['category', 'translations', 'related.category', 'related.translations'])->get();
        $result = [];

        foreach ($rows as $row) {
            /** @var FaqTranslation|null $translation */
            $translation = $row->translations->firstWhere('locale', $locale);

            if ($translation === null || $row->category === null) {
                continue;
            }

            $related = [];

            foreach ($row->related as $other) {
                if ($other->category !== null && $other->translations->firstWhere('locale', $locale) !== null) {
                    $related[] = $other->category->slug.'/'.$other->slug;
                }
            }

            $result[] = new HelpArticle(
                category: $row->category->slug,
                slug: $row->slug,
                title: $translation->question,
                summary: $translation->summary,
                tags: array_map('strval', $row->tags),
                related: $related,
                popular: $row->is_popular,
                order: $row->position,
                updated: $translation->updated_at->toDateString(),
                body: trim($translation->body),
            );
        }

        return $result;
    }

    private function parse(string $file): ?HelpArticle
    {
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($file));

        if (preg_match('/\A---\n(.*?)\n---\n(.*)\z/s', $raw, $parts) !== 1) {
            return null;
        }

        $meta = [];

        foreach (explode("\n", $parts[1]) as $line) {
            if (preg_match('/^([a-z]+):\s*(.*)$/', $line, $pair) === 1) {
                $meta[$pair[1]] = trim($pair[2]);
            }
        }

        $list = fn (string $key): array => array_values(array_filter(array_map('trim', explode(',', $meta[$key] ?? ''))));
        $category = basename(dirname($file));

        if (! isset($meta['title'], $meta['summary'])) {
            return null;
        }

        return new HelpArticle(
            category: $category,
            slug: basename($file, '.md'),
            title: $meta['title'],
            summary: $meta['summary'],
            tags: $list('tags'),
            related: $list('related'),
            popular: ($meta['popular'] ?? '') === 'true',
            order: (int) ($meta['order'] ?? 100),
            updated: $meta['updated'] ?? '',
            body: trim($parts[2]),
        );
    }
}
