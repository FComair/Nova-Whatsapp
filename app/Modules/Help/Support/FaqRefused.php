<?php

declare(strict_types=1);

namespace App\Modules\Help\Support;

use RuntimeException;

/**
 * Une action sur une FAQ ou une catégorie n'est pas permise (docs/30). `$reason` est une clé de `lang/*\/faq.php` (errors.*) et `$params`
 * ses remplacements : l'écran affiche le message dans la langue de la personne.
 */
final class FaqRefused extends RuntimeException
{
    /**
     * @param  array<string, string|int>  $params
     */
    public function __construct(public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($reason);
    }

    /** @param  list<string>  $locales */
    public static function missingTranslations(array $locales): self
    {
        return new self('missing_translations', ['locales' => implode(', ', array_map('strtoupper', $locales))]);
    }

    public static function incompleteTranslation(string $locale): self
    {
        return new self('incomplete_translation', ['locale' => strtoupper($locale)]);
    }

    public static function state(string $action): self
    {
        return new self('state', ['action' => $action]);
    }

    public static function slugTaken(string $slug): self
    {
        return new self('slug_taken', ['slug' => $slug]);
    }

    public static function slugInvalid(string $slug): self
    {
        return new self('slug_invalid', ['slug' => $slug]);
    }

    public static function categoryNotEmpty(int $count): self
    {
        return new self('category_not_empty', ['count' => $count]);
    }

    public static function noContent(): self
    {
        return new self('no_content');
    }
}
