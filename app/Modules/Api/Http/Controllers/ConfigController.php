<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Concerns\RespondsWithJson;
use App\Modules\Booking\Services\PolicyService;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Catalog\Models\Service;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Configuration lue par le bot (scope config:read) : sites et horaires, services réservables, praticiens actifs, politiques.
 * Aucune donnée personnelle de client ni de membre de l'équipe (pas d'e-mail ni de téléphone).
 */
final class ConfigController
{
    use RespondsWithJson;

    public function __construct(private readonly CurrentTenant $current, private readonly PolicyService $policies) {}

    public function show(): JsonResponse
    {
        $organization = $this->current->organization() ?? abort(403);
        $hours = BusinessHour::query()->orderBy('weekday')->orderBy('starts_at')->get()->groupBy('location_id');
        $offered = DB::table('practitioner_services')->get()->groupBy('practitioner_id');
        $policy = $this->policies->for($organization);

        return new JsonResponse(['data' => [
            'organization' => ['name' => $organization->name, 'status' => $organization->status],
            'locations' => Location::query()->orderBy('created_at')->get()->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'timezone' => $location->timezone,
                'hours' => ($hours[$location->id] ?? collect())->map(fn (BusinessHour $hour): array => ['weekday' => $hour->weekday, 'start' => substr($hour->starts_at, 0, 5), 'end' => substr($hour->ends_at, 0, 5)])->values()->all(),
            ])->values()->all(),
            'services' => Service::query()->where('is_active', true)->where('bookable_online', true)->orderBy('position')->get()->map(fn (Service $service): array => [
                'id' => $service->id,
                'name' => $service->name,
                'description' => $service->description,
                'duration_minutes' => $service->duration_minutes,
                'price_cents' => $service->price_cents,
                'vat_rate' => $service->vat_rate,
            ])->values()->all(),
            'practitioners' => Practitioner::query()->where('status', Practitioner::STATUS_ACTIVE)->orderBy('position')->get()->map(fn (Practitioner $practitioner): array => [
                'id' => $practitioner->id,
                'name' => $practitioner->name,
                'service_ids' => ($offered[$practitioner->id] ?? collect())->pluck('service_id')->values()->all(),
            ])->values()->all(),
            'policies' => [
                'min_notice_hours' => $policy->min_notice_hours,
                'max_horizon_days' => $policy->max_horizon_days,
                'cancellation_window_hours' => $policy->cancellation_window_hours,
                'hold_minutes' => $policy->hold_minutes,
            ],
        ]]);
    }
}
