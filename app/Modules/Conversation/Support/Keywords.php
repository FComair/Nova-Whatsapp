<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Support;

/**
 * Listes de mots reconnus dans les messages des clients (fichiers de traduction `conversation.keywords.*`).
 *
 * Les listes des trois langues sont fusionnées : un client peut écrire dans une autre langue que celle de l'établissement.
 * Tout est comparé sans accents ni casse (voir Text::normalize).
 */
final class Keywords
{
    /** @var array<string, list<string>> */
    private static array $cache = [];

    /**
     * @return list<string> mots normalisés d'un groupe
     */
    public static function list(string $group): array
    {
        return self::$cache[$group] ??= self::load($group);
    }

    /** Le message (déjà normalisé) est exactement l'un des mots du groupe. */
    public static function equals(string $message, string $group): bool
    {
        return in_array($message, self::list($group), true);
    }

    /** Le message (déjà normalisé) contient l'un des mots du groupe. */
    public static function contains(string $message, string $group): bool
    {
        foreach (self::list($group) as $word) {
            if ($word !== '' && str_contains($message, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Jours de la semaine : nom normalisé → numéro (0 = dimanche … 6 = samedi).
     *
     * @return array<string, int>
     */
    public static function weekdays(): array
    {
        $map = [];

        foreach ((array) config('rendivoo.locales') as $locale) {
            $names = trans('conversation.keywords.weekdays', [], (string) $locale);

            if (! is_array($names)) {
                continue;
            }

            foreach ($names as $number => $name) {
                $map[Text::normalize((string) $name)] = (int) $number;
            }
        }

        return $map;
    }

    /** Vide la mémoire (tests). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * @return list<string>
     */
    private static function load(string $group): array
    {
        $words = [];

        foreach ((array) config('rendivoo.locales') as $locale) {
            $list = trans('conversation.keywords.'.$group, [], (string) $locale);

            if (! is_array($list)) {
                continue;
            }

            foreach ($list as $word) {
                $words[] = Text::normalize((string) $word);
            }
        }

        return array_values(array_unique(array_filter($words, static fn (string $word): bool => $word !== '')));
    }
}
