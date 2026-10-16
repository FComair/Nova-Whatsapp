<?php

declare(strict_types=1);

namespace App\Modules\Booking\Support;

use Carbon\CarbonImmutable;

/**
 * Lecture d'une date et d'une heure saisies dans le fuseau du prestataire, puis conversion en UTC (la seule heure enregistrée).
 *
 * Passage à l'heure d'été : à Bruxelles, 02:30 le dernier dimanche de mars n'existe pas (on passe de 02:00 à 03:00). PHP la
 * décalerait silencieusement à 03:30 ; on la refuse plutôt que d'enregistrer une heure que personne n'a demandée.
 * Passage à l'heure d'hiver : 02:30 existe deux fois ; on retient la première occurrence (heure d'été), toujours la même.
 */
final class LocalTime
{
    /** Vrai si cette heure locale existe réellement dans ce fuseau (donc ne tombe pas dans le saut du printemps). */
    public static function exists(string $date, string $time, string $timezone): bool
    {
        $local = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$time, $timezone);

        return $local !== false && $local->format('Y-m-d H:i') === $date.' '.$time;
    }

    public static function toUtc(string $date, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$time, $timezone)->utc();
    }
}
