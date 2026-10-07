<?php

declare(strict_types=1);

namespace App\Modules\Booking\Support;

use Carbon\CarbonImmutable;

/**
 * Demande de réservation transmise au cœur de réservation.
 */
final readonly class NewBooking
{
    /**
     * @param  string  $source  dashboard | whatsapp | staff_whatsapp | api
     * @param  bool  $hold  vrai : créneau bloqué quelques minutes en attente de confirmation (conversation WhatsApp)
     * @param  bool  $enforcePolicies  vrai pour les clients (délai minimum, horizon) ; faux pour la saisie par le personnel
     */
    public function __construct(
        public string $locationId,
        public string $serviceId,
        public string $practitionerId,
        public string $customerId,
        public CarbonImmutable $startsAt,
        public string $source,
        public Actor $actor,
        public bool $hold = false,
        public bool $enforcePolicies = true,
        public ?string $notes = null,
        public ?string $idempotencyKey = null,
    ) {}
}
