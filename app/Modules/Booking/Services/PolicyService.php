<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\BookingPolicy;
use App\Modules\Tenancy\Models\Organization;

/**
 * Politiques de réservation de l'organisation courante : lecture (avec valeurs par défaut) et mise à jour tracée.
 */
final class PolicyService
{
    public function for(Organization $organization): BookingPolicy
    {
        return BookingPolicy::query()->firstOrCreate(['org_id' => $organization->id], BookingPolicy::DEFAULTS);
    }

    /**
     * @param  array{min_notice_hours: int, max_horizon_days: int, cancellation_window_hours: int, no_show_fee_cents: int, allow_overbooking: bool, hold_minutes: int, reminder_hours_before: int}  $data
     */
    public function update(Organization $organization, array $data): BookingPolicy
    {
        $policy = $this->for($organization);
        $policy->fill($data);
        $diff = collect($policy->getDirty())->map(fn ($new, string $key): array => [$policy->getOriginal($key), $new])->all();
        $policy->save();

        if ($diff !== []) {
            Audit::record('booking_policies.updated', 'user', actorId: auth()->id(), orgId: $organization->id, entityType: 'booking_policies', entityId: $organization->id, diff: $diff);
        }

        return $policy;
    }
}
