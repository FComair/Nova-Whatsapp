<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

/**
 * Comparaison d'un chiffre avec celui de la période précédente : écart absolu et pourcentage d'évolution, jamais présentés
 * comme bons ou mauvais. Le pourcentage n'est calculé que sur une base qui le permet : départ à zéro ou trop petit
 * (quelques unités), il serait trompeur ; l'écart absolu reste alors la seule lecture.
 */
final class Comparison
{
    /** Base minimale pour un pourcentage, par nature de chiffre. */
    private const MIN_BASE = ['count' => 5, 'money' => 5000, 'minutes' => 120];

    /** Nombre minimal d'éléments derrière un taux pour comparer deux périodes (sinon : trop peu de cas). */
    private const MIN_SAMPLE = 10;

    /**
     * Chiffre absolu (nombre, montant en centimes ou minutes).
     *
     * @param  'count'|'money'|'minutes'  $kind
     * @return array{value: int|float, previous: int|float|null, delta: int|float|null, pct: float|null, kind: string, note: string|null}
     */
    public static function amount(int|float $current, int|float|null $previous, string $kind = 'count'): array
    {
        if ($previous === null) {
            return ['value' => $current, 'previous' => null, 'delta' => null, 'pct' => null, 'kind' => $kind, 'note' => null];
        }

        $delta = $current - $previous;
        $base = self::MIN_BASE[$kind];
        $pct = null;
        $note = null;

        if ($previous == 0) {
            $note = 'zero_baseline';
        } elseif ($previous < $base) {
            $note = 'small_baseline';
        } else {
            $pct = round($delta / $previous * 100, 1);
        }

        return ['value' => $current, 'previous' => $previous, 'delta' => $delta, 'pct' => $pct, 'kind' => $kind, 'note' => $note];
    }

    /**
     * Taux (0 à 1) : l'écart est donné en points de pourcentage, pas en pourcentage d'un pourcentage.
     *
     * @return array{value: float|null, previous: float|null, delta: float|null, pct: null, kind: string, note: string|null}
     */
    public static function rate(?float $current, ?float $previous, int $currentSample = 0, int $previousSample = 0): array
    {
        $note = null;
        $delta = null;

        if ($current === null || $previous === null) {
            $note = $previous === null && $current !== null ? 'no_baseline' : null;
        } elseif ($currentSample < self::MIN_SAMPLE || $previousSample < self::MIN_SAMPLE) {
            $note = 'small_baseline';
        } else {
            $delta = round(($current - $previous) * 100, 1);
        }

        return ['value' => $current, 'previous' => $previous, 'delta' => $delta, 'pct' => null, 'kind' => 'rate', 'note' => $note];
    }

    /** Division sûre : null quand il n'y a rien à diviser (un taux « 0 % » serait faux). */
    public static function ratio(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? $part / $whole : null;
    }
}
