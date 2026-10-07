<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Montants : toujours des centimes entiers en base et dans le code, jamais des flottants.
 */
final class Money
{
    /**
     * Convertit une saisie en euros (« 40 », « 40,5 », « 40.50 ») en centimes, sans passer par un flottant.
     * Renvoie null si la saisie n'est pas un montant valide (au plus 2 décimales, pas de signe).
     */
    public static function parseToCents(string $input): ?int
    {
        $input = trim(str_replace(["\u{00A0}", ' ', '€'], '', $input));

        if (! preg_match('/^(\d{1,7})(?:[.,](\d{1,2}))?$/', $input, $matches)) {
            return null;
        }

        return ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');
    }
}
