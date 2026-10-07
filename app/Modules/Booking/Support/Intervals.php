<?php

declare(strict_types=1);

namespace App\Modules\Booking\Support;

/**
 * Opérations sur des listes d'intervalles semi-ouverts [début, fin[ exprimés en secondes Unix (UTC).
 * Tout le moteur de disponibilité raisonne en UTC : l'heure d'été/hiver n'y a aucun effet, seule la conversion des
 * heures locales d'un site en UTC en tient compte.
 */
final class Intervals
{
    /**
     * Trie et fusionne les intervalles qui se chevauchent ou se touchent ; élimine les intervalles vides.
     *
     * @param  list<array{0: int, 1: int}>  $intervals
     * @return list<array{0: int, 1: int}>
     */
    public static function normalize(array $intervals): array
    {
        $intervals = array_values(array_filter($intervals, fn (array $i): bool => $i[1] > $i[0]));
        usort($intervals, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];

        foreach ($intervals as [$start, $end]) {
            $last = count($merged) - 1;

            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $a
     * @param  list<array{0: int, 1: int}>  $b
     * @return list<array{0: int, 1: int}>
     */
    public static function union(array $a, array $b): array
    {
        return self::normalize([...$a, ...$b]);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $a
     * @param  list<array{0: int, 1: int}>  $b
     * @return list<array{0: int, 1: int}>
     */
    public static function intersect(array $a, array $b): array
    {
        $a = self::normalize($a);
        $b = self::normalize($b);
        $result = [];

        foreach ($a as [$aStart, $aEnd]) {
            foreach ($b as [$bStart, $bEnd]) {
                $start = max($aStart, $bStart);
                $end = min($aEnd, $bEnd);

                if ($end > $start) {
                    $result[] = [$start, $end];
                }
            }
        }

        return self::normalize($result);
    }

    /**
     * Retire de `$a` tout ce qui est couvert par `$b`.
     *
     * @param  list<array{0: int, 1: int}>  $a
     * @param  list<array{0: int, 1: int}>  $b
     * @return list<array{0: int, 1: int}>
     */
    public static function subtract(array $a, array $b): array
    {
        $result = self::normalize($a);

        foreach (self::normalize($b) as [$bStart, $bEnd]) {
            $next = [];

            foreach ($result as [$start, $end]) {
                if ($bEnd <= $start || $bStart >= $end) {
                    $next[] = [$start, $end];

                    continue;
                }

                if ($bStart > $start) {
                    $next[] = [$start, $bStart];
                }

                if ($bEnd < $end) {
                    $next[] = [$bEnd, $end];
                }
            }

            $result = $next;
        }

        return $result;
    }

    /** Deux intervalles semi-ouverts se chevauchent-ils ? (deux rendez-vous bout à bout ne se chevauchent pas) */
    public static function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $bStart < $aEnd;
    }
}
