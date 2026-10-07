<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Audit\Audit;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Tenancy\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Horaires d'ouverture hebdomadaires d'un site. Plusieurs plages par jour ; un jour sans plage est fermé.
 * L'absence de chevauchement est garantie par la base (contrainte d'exclusion), pas seulement par la validation.
 */
final class BusinessHoursService
{
    /**
     * @return array<int, list<array{start: string, end: string}>> plages par jour ISO (1 = lundi ... 7 = dimanche), jours fermés inclus
     */
    public function weekly(Location $location): array
    {
        $week = array_fill_keys(range(1, 7), []);

        BusinessHour::query()
            ->where('location_id', $location->id)
            ->orderBy('weekday')->orderBy('starts_at')
            ->get()
            ->each(function (BusinessHour $hour) use (&$week): void {
                $week[$hour->weekday][] = ['start' => substr($hour->starts_at, 0, 5), 'end' => substr($hour->ends_at, 0, 5)];
            });

        return $week;
    }

    /**
     * Remplace tous les horaires du site par ceux fournis (opération atomique) et trace le changement.
     *
     * @param  array<int, list<array{start: string, end: string}>>  $week
     */
    public function replace(Location $location, array $week): void
    {
        $before = $this->weekly($location);

        DB::transaction(function () use ($location, $week): void {
            BusinessHour::query()->where('location_id', $location->id)->delete();

            foreach ($week as $weekday => $ranges) {
                foreach ($ranges as $range) {
                    BusinessHour::query()->create([
                        'org_id' => $location->org_id,
                        'location_id' => $location->id,
                        'weekday' => $weekday,
                        'starts_at' => $range['start'],
                        'ends_at' => $range['end'],
                    ]);
                }
            }
        });

        Audit::record(
            'business_hours.updated',
            'user',
            actorId: auth()->id(),
            orgId: $location->org_id,
            entityType: 'location',
            entityId: $location->id,
            diff: ['before' => $before, 'after' => $week],
        );
    }

    public function hasAnyOpenRange(Location $location): bool
    {
        return BusinessHour::query()->where('location_id', $location->id)->exists();
    }
}
