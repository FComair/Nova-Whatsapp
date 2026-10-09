<?php

declare(strict_types=1);

namespace App\Modules\Help\Support;

/**
 * Un article d'aide dans une langue : ce que dit son fichier Markdown (en-tête et corps).
 */
final readonly class HelpArticle
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $related  « catégorie/article »
     */
    public function __construct(
        public string $category,
        public string $slug,
        public string $title,
        public string $summary,
        public array $tags,
        public array $related,
        public bool $popular,
        public int $order,
        public string $updated,
        public string $body,
    ) {}

    public function key(): string
    {
        return $this->category.'/'.$this->slug;
    }
}
