<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Support\Slot;
use RuntimeException;

/**
 * Le créneau demandé n'est pas réservable (erreur E10 : « Ce créneau vient d'être pris »).
 * Porte les prochains créneaux libres à proposer au client.
 */
final class SlotUnavailable extends RuntimeException
{
    /**
     * @param  'policy'|'outside_hours'|'taken'  $reason
     * @param  list<Slot>  $suggestions
     */
    public function __construct(public readonly string $reason, public readonly array $suggestions = [])
    {
        parent::__construct("Créneau indisponible ({$reason}).");
    }
}
