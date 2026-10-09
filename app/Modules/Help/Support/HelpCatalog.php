<?php

declare(strict_types=1);

namespace App\Modules\Help\Support;

use Illuminate\Support\Str;

/**
 * Lit les articles d'aide (resources/help/{langue}/{catégorie}/{article}.md) et prépare leur affichage.
 *
 * Chaque fichier commence par un en-tête simple entre deux lignes « --- » : title, summary, tags (séparés par des virgules),
 * related (« catégorie/article » séparés par des virgules), popular (true), order (nombre), updated (AAAA-MM-JJ).
 * Le corps est du Markdown ; le HTML brut est retiré au rendu.
 */
final class HelpCatalog
{
    /** @var array<string, array<string, HelpArticle>> */
    private array $cache = [];

    public function __construct(private readonly string $path) {}

    /** @return list<string> */
    public function categories(): array
    {
        return array_keys((array) config('help.categories'));
    }

    /** @return array<string, HelpArticle> clé « catégorie/article », triée par catégorie puis ordre puis titre */
    public function articles(string $locale): array
    {
        if (isset($this->cache[$locale])) {
            return $this->cache[$locale];
        }

        $articles = [];
        $order = array_flip($this->categories());

        foreach (glob($this->path.'/'.$locale.'/*/*.md') ?: [] as $file) {
            $article = $this->parse($file);

            if ($article !== null && isset($order[$article->category])) {
                $articles[$article->key()] = $article;
            }
        }

        uasort($articles, fn (HelpArticle $a, HelpArticle $b): int => [$order[$a->category], $a->order, $a->title] <=> [$order[$b->category], $b->order, $b->title]);

        return $this->cache[$locale] = $articles;
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
        $headings = [];
        $html = (string) preg_replace_callback('/<h2>(.*?)<\/h2>/s', function (array $match) use (&$headings): string {
            $text = trim(html_entity_decode(strip_tags($match[1])));
            $id = Str::slug($text) ?: 'section-'.(count($headings) + 1);
            $headings[] = ['id' => $id, 'text' => $text];

            return '<h2 id="'.e($id).'">'.$match[1].'</h2>';
        }, $this->html($article->body));

        return ['html' => $html, 'headings' => $headings];
    }

    private function html(string $markdown): string
    {
        return Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
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
