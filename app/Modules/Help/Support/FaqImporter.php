<?php

declare(strict_types=1);

namespace App\Modules\Help\Support;

use App\Modules\Admin\Models\PlatformFaqArticle;
use App\Modules\Admin\Models\PlatformFaqCategory;
use App\Modules\Admin\Models\PlatformFaqTranslation;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Help\Models\FaqArticle;
use App\Modules\Help\Models\FaqCategory;
use Illuminate\Support\Facades\DB;

/**
 * Importe dans la base les articles d'aide livrés en fichiers Markdown (docs/30) pour que le Super Admin puisse les modifier. Sûr à
 * relancer : une catégorie ou un article déjà en base n'est jamais écrasé. Les articles importés sont publiés, traductions prêtes.
 */
final class FaqImporter
{
    public function __construct(private readonly HelpCatalog $catalog) {}

    /**
     * @return array{categories: int, articles: int, skipped: int}
     */
    public function import(): array
    {
        $result = ['categories' => 0, 'articles' => 0, 'skipped' => 0];
        $connection = DB::connection(PlatformAudit::CONNECTION);

        $connection->transaction(function () use (&$result): void {
            $index = 0;

            foreach (array_keys((array) config('help.categories')) as $slug) {
                $index++;

                if (PlatformFaqCategory::query()->where('slug', $slug)->exists()) {
                    continue;
                }

                PlatformFaqCategory::query()->create([
                    'slug' => $slug,
                    'icon' => (string) config("help.categories.{$slug}"),
                    'position' => $index * 10,
                    'is_active' => true,
                    'names' => $this->lang($slug, 'name'),
                    'descriptions' => $this->lang($slug, 'description'),
                ]);
                $result['categories']++;
            }

            $files = [];

            foreach (FaqCategory::LOCALES as $locale) {
                foreach ($this->catalog->fileArticles($locale) as $key => $article) {
                    $files[$key][$locale] = $article;
                }
            }

            $created = [];

            foreach ($files as $key => $byLocale) {
                [$category, $slug] = explode('/', $key, 2);
                $categoryRow = PlatformFaqCategory::query()->where('slug', $category)->first();

                if ($categoryRow === null || PlatformFaqArticle::query()->where('category_id', $categoryRow->id)->where('slug', $slug)->exists()) {
                    $result['skipped']++;

                    continue;
                }

                $primary = $byLocale['fr'] ?? reset($byLocale);
                $article = PlatformFaqArticle::query()->create([
                    'category_id' => $categoryRow->id,
                    'slug' => $slug,
                    'status' => FaqArticle::PUBLISHED,
                    'publication' => count($byLocale) === 3 ? 'all' : 'available',
                    'position' => $primary->order,
                    'is_popular' => $primary->popular,
                    'tags' => $primary->tags,
                    'published_at' => now(),
                ]);

                foreach ($byLocale as $locale => $file) {
                    PlatformFaqTranslation::query()->create([
                        'article_id' => $article->id, 'locale' => $locale, 'question' => $file->title, 'summary' => $file->summary, 'body' => $file->body, 'is_ready' => true,
                    ]);
                }

                $created[$key] = [$article->id, $primary->related];
                $result['articles']++;
            }

            // Articles liés : une fois que tous existent.
            foreach ($created as [$id, $related]) {
                foreach ($related as $position => $relatedKey) {
                    [$relCategory, $relSlug] = array_pad(explode('/', $relatedKey, 2), 2, '');
                    $target = PlatformFaqArticle::query()->whereHas('category', fn ($q) => $q->where('slug', $relCategory))->where('slug', $relSlug)->value('id');

                    if ($target !== null && $target !== $id) {
                        DB::connection(PlatformAudit::CONNECTION)->table('faq_article_related')->updateOrInsert(['article_id' => $id, 'related_id' => $target], ['position' => $position]);
                    }
                }
            }
        });

        PlatformAudit::system('faq.imported', null, 'faq_article', null, $result);
        $this->catalog->flush();

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function lang(string $slug, string $field): array
    {
        $texts = [];

        foreach (FaqCategory::LOCALES as $locale) {
            $value = (string) __("ui.help.categories.{$slug}.{$field}", [], $locale);

            if ($value !== '' && ! str_starts_with($value, 'ui.help.categories.')) {
                $texts[$locale] = $value;
            }
        }

        return $texts;
    }
}
