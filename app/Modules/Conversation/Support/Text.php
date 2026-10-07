<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Support;

use Illuminate\Support\Str;

/**
 * Petits outils de texte du dialogue WhatsApp : normalisation des messages entrants, pastilles numérotées, prix.
 */
final class Text
{
    /** Minuscules, sans accents, apostrophes unifiées, espaces réduits : base de toutes les comparaisons de mots. */
    public static function normalize(string $value): string
    {
        $value = str_replace(['’', '‘', '`'], "'", $value);
        $value = Str::lower(Str::ascii($value));

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /** Pastille numérotée de WhatsApp : 1 → « 1️⃣ ». */
    public static function digit(int $number): string
    {
        return implode('', array_map(static fn (string $digit): string => $digit."\u{FE0F}\u{20E3}", str_split((string) $number)));
    }

    /** Montant en centimes → « 25 » ou « 25,50 » (virgule en fr et nl, point en en). */
    public static function amount(int $cents, string $locale): string
    {
        $euros = intdiv($cents, 100);
        $rest = $cents % 100;

        return $rest === 0 ? (string) $euros : $euros.($locale === 'en' ? '.' : ',').sprintf('%02d', $rest);
    }

    /** Un nombre entier saisi seul (« 2 », « 2. »), ou null. */
    public static function number(string $value): ?int
    {
        $value = trim($value, " \t\n\r\0\x0B.");

        return preg_match('/^\d{1,3}$/', $value) === 1 ? (int) $value : null;
    }

    public static function isEmail(string $value): bool
    {
        $value = trim($value);

        return strlen($value) >= 5 && strlen($value) <= 254 && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/i', $value) === 1;
    }

    /** Un nom de personne : 2 à 120 caractères, au moins une lettre. */
    public static function isPersonName(string $value): bool
    {
        $value = trim($value);
        $length = mb_strlen($value);

        return $length >= 2 && $length <= 120 && preg_match('/\p{L}/u', $value) === 1;
    }

    /** Heure saisie « 9:30 », « 09:30 », « 9h30 » → « 09:30 », ou null. */
    public static function time(string $value): ?string
    {
        if (preg_match('/^([01]?\d|2[0-3])\s*[:h]\s*([0-5]\d)$/i', trim($value), $match) !== 1) {
            return null;
        }

        return str_pad($match[1], 2, '0', STR_PAD_LEFT).':'.$match[2];
    }
}
