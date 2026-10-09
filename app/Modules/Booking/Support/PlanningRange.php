<?php

declare(strict_types=1);

namespace App\Modules\Booking\Support;

/**
 * Plage horaire affichée par la grille du planning : de 2 h avant la première heure d'ouverture ou de travail à 2 h après la
 * dernière, arrondie à l'heure, bornée à la journée (00:00–24:00). Cela laisse voir la préparation et la clôture autour des
 * horaires sans toucher aux horaires eux-mêmes ni aux règles de réservation.
 *
 * Tous les jours de la semaine partagent le même axe : la plage couvre donc les horaires de toute la semaine. Un rendez-vous
 * placé hors de cette plage l'élargit (jamais un rendez-vous caché). Sans aucun horaire, plage par défaut de 06:00 à 22:00.
 */
final class PlanningRange
{
    /** Marge ajoutée avant l'ouverture et après la fermeture, en minutes. */
    public const BUFFER = 120;

    public const DEFAULT_FROM = 360;

    public const DEFAULT_TO = 1320;

    /**
     * @param  list<array{0: int, 1: int}>  $opening  [début, fin] en minutes depuis minuit : horaires du site et des praticiens
     * @param  list<array{0: int, 1: int}>  $bookings  [début, fin] des rendez-vous affichés
     * @return array{from: int, to: int}
     */
    public static function compute(array $opening, array $bookings = []): array
    {
        if ($opening === []) {
            $from = self::DEFAULT_FROM;
            $to = self::DEFAULT_TO;
        } else {
            $from = max(0, (int) (floor((min(array_column($opening, 0)) - self::BUFFER) / 60) * 60));
            $to = min(1440, (int) (ceil((max(array_column($opening, 1)) + self::BUFFER) / 60) * 60));
        }

        if ($bookings !== []) {
            $from = min($from, (int) (floor(min(array_column($bookings, 0)) / 60) * 60));
            $to = max($to, min(1440, (int) (ceil(max(array_column($bookings, 1)) / 60) * 60)));
        }

        return ['from' => $from, 'to' => $to];
    }
}
