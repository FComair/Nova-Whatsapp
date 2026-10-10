<?php

declare(strict_types=1);

namespace App\Modules\Help\Support;

use App\Modules\Help\Models\FaqArticle;
use App\Modules\Help\Models\FaqCategory;

/**
 * Règles de publication d'une FAQ (docs/30 §4) :
 *  - « available » (langues disponibles) : les langues obligatoires (`help.required_locales`, par défaut fr et en) doivent être prêtes ;
 *    chaque autre langue n'est montrée au client que si sa traduction est prête.
 *  - « all » (toutes les langues) : fr, nl et en doivent toutes être prêtes.
 * La base applique la même règle côté client (traductions non prêtes jamais lues ; mode « all » seulement avec trois traductions prêtes).
 */
final class FaqPublishing
{
    /**
     * Langues qui doivent être prêtes pour publier.
     *
     * @return list<string>
     */
    public static function required(string $mode): array
    {
        if ($mode === 'all') {
            return FaqCategory::LOCALES;
        }

        return array_values(array_intersect(FaqCategory::LOCALES, (array) config('help.required_locales', ['fr', 'en'])));
    }

    /**
     * Langues qui empêchent la publication (traduction absente ou pas prête).
     *
     * @return list<string>
     */
    public static function missing(FaqArticle $article, ?string $mode = null): array
    {
        $ready = $article->translations->where('is_ready', true)->pluck('locale')->all();

        return array_values(array_diff(self::required($mode ?? $article->publication), $ready));
    }

    /**
     * État de chaque langue : « ready » (prête), « draft » (commencée, pas prête) ou « missing » (aucun texte).
     *
     * @return array<string, 'ready'|'draft'|'missing'>
     */
    public static function completeness(FaqArticle $article): array
    {
        $state = [];

        foreach (FaqCategory::LOCALES as $locale) {
            $translation = $article->translations->firstWhere('locale', $locale);
            $state[$locale] = $translation === null || (trim($translation->question) === '' && trim($translation->body) === '')
                ? 'missing'
                : ($translation->is_ready ? 'ready' : 'draft');
        }

        return $state;
    }
}
