<?php

declare(strict_types=1);

namespace App\Modules\Booking\Support;

use Carbon\CarbonImmutable;

/**
 * Créneau réservable : début et fin en UTC, praticien et site.
 */
final readonly class Slot
{
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $practitionerId,
        public string $locationId,
    ) {}

    /**
     * @return array{starts_at: string, ends_at: string, practitioner_id: string, location_id: string}
     */
    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->utc()->toIso8601String(),
            'ends_at' => $this->endsAt->utc()->toIso8601String(),
            'practitioner_id' => $this->practitionerId,
            'location_id' => $this->locationId,
        ];
    }
}
