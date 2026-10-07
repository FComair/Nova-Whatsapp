<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Concerns;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingRuleViolation;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Booking\Support\Slot;
use App\Modules\Customers\Models\Customer;
use Illuminate\Http\JsonResponse;

/**
 * Format de réponse de l'API interne : erreurs `{ "error": { "code", "message" } }` avec des codes stables,
 * lisibles par machine (le bot les traduit dans la langue du client).
 */
trait RespondsWithJson
{
    /**
     * @param  array<string, mixed>  $extra
     */
    protected function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message, ...$extra]], $status);
    }

    protected function slotUnavailable(SlotUnavailable $e): JsonResponse
    {
        return $this->error('slot_unavailable', 'Le créneau n\'est pas disponible.', 409, [
            'reason' => $e->reason,
            'suggestions' => array_map(fn (Slot $slot): array => $this->slot($slot), $e->suggestions),
        ]);
    }

    protected function ruleViolation(BookingRuleViolation $e): JsonResponse
    {
        $status = $e->rule === BookingRuleViolation::CUSTOMER_BLOCKED ? 403 : 422;

        return $this->error($e->rule, 'Règle de réservation non respectée.', $status);
    }

    /**
     * @return array<string, mixed>
     */
    protected function slot(Slot $slot): array
    {
        return ['starts_at' => $slot->startsAt->toIso8601String(), 'ends_at' => $slot->endsAt->toIso8601String(), 'practitioner_id' => $slot->practitionerId, 'location_id' => $slot->locationId];
    }

    /**
     * @return array<string, mixed>
     */
    protected function booking(Booking $booking): array
    {
        return [
            'id' => $booking->id,
            'status' => $booking->status,
            'starts_at' => $booking->starts_at->utc()->toIso8601String(),
            'ends_at' => $booking->ends_at->utc()->toIso8601String(),
            'location_id' => $booking->location_id,
            'service_id' => $booking->service_id,
            'practitioner_id' => $booking->practitioner_id,
            'customer_id' => $booking->customer_id,
            'expires_at' => $booking->expires_at?->utc()->toIso8601String(),
            'price_cents' => $booking->price_cents,
            'currency' => $booking->currency,
            'source' => $booking->source,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customer(Customer $customer): array
    {
        return ['id' => $customer->id, 'phone' => $customer->phone_e164, 'name' => $customer->name, 'language' => $customer->language, 'is_blocked' => $customer->is_blocked];
    }
}
