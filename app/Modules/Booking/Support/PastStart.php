<?php

declare(strict_types=1);

namespace App\Modules\Booking\Support;

use Carbon\CarbonImmutable;

/**
 * Règle « pas de rendez-vous dans le passé » pour les rendez-vous saisis depuis le dashboard. Elle ne porte que sur le début :
 * durée, horaires, disponibilité praticien et délai minimum restent jugés par le cœur de réservation.
 *
 * Le début est comparé à l'instant présent, à la minute près : la minute en cours reste réservable (un formulaire ouvert à
 * 14:30:20 peut encore viser 14:30). Les instants sont comparés en UTC, donc le fuseau du site (heure d'été comprise) et le
 * changement de jour sont traités par la conversion qui produit `$startsAt`, jamais par une comparaison de dates écrites.
 */
final class PastStart
{
    public static function isPast(CarbonImmutable $startsAt, ?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $startsAt->utc()->getTimestamp() < $now->utc()->startOfMinute()->getTimestamp();
    }
}
