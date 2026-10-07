<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Concerns\RespondsWithJson;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Catalog\Models\Service;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Créneaux réservables (scope availability:read). Applique les politiques de réservation des clients.
 */
final class AvailabilityController
{
    use RespondsWithJson;

    private const MAX_DAYS = 31;

    private const MAX_SLOTS = 500;

    public function __construct(private readonly CurrentTenant $current, private readonly AvailabilityService $availability) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            'service_id' => ['required', 'uuid', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'practitioner_id' => ['nullable', 'uuid', Rule::exists('practitioners', 'id')->whereNull('deleted_at')],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) >= self::MAX_DAYS) {
            return $this->error('range_too_large', 'La période ne peut pas dépasser '.self::MAX_DAYS.' jours.', 422);
        }

        $service = Service::query()->findOrFail($data['service_id']);

        if (! $service->is_active || ! $service->bookable_online) {
            return $this->error('service_unavailable', 'Ce service n\'est pas réservable.', 422);
        }

        $slots = $this->availability->slots(
            $this->current->organization() ?? abort(403),
            Location::query()->findOrFail($data['location_id']),
            $service,
            $data['from'],
            $data['to'],
            isset($data['practitioner_id']) ? Practitioner::query()->findOrFail($data['practitioner_id']) : null,
        );

        return new JsonResponse([
            'data' => array_map(fn ($slot): array => $this->slot($slot), array_slice($slots, 0, self::MAX_SLOTS)),
            'truncated' => count($slots) > self::MAX_SLOTS,
        ]);
    }
}
