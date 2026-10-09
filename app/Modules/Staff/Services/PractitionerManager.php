<?php

declare(strict_types=1);

namespace App\Modules\Staff\Services;

use App\Modules\Audit\Audit;
use App\Modules\Billing\Support\PlanLimits;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Praticiens de l'organisation courante : création, services proposés, planning hebdomadaire.
 *
 * Règle de l'offre (flowchart 1C/E8) : au-delà du nombre de praticiens inclus, le praticien est créé en brouillon
 * (`draft`) : il est conservé mais non réservable, jusqu'à un changement d'offre.
 */
final class PractitionerManager
{
    public function __construct(private readonly PlanLimits $limits) {}

    /** Nombre de praticiens comptés dans la limite de l'offre (actifs). */
    public function usedSlots(): int
    {
        return Practitioner::query()->where('status', Practitioner::STATUS_ACTIVE)->count();
    }

    public function limit(Organization $organization): ?int
    {
        return $this->limits->limit($organization, 'practitioners');
    }

    public function isAtLimit(Organization $organization): bool
    {
        $limit = $this->limit($organization);

        return $limit !== null && $this->usedSlots() >= $limit;
    }

    /**
     * @param  array{name: string, color: string, bio?: string|null, service_ids?: list<string>, copy_hours?: bool}  $data
     * @return array{practitioner: Practitioner, over_limit: bool}
     */
    public function create(Organization $organization, array $data, ?string $userId = null): array
    {
        return DB::transaction(function () use ($organization, $data, $userId): array {
            $overLimit = $this->isAtLimit($organization);

            $practitioner = Practitioner::query()->create([
                'org_id' => $organization->id,
                'user_id' => $userId,
                'name' => $data['name'],
                'bio' => $data['bio'] ?? null,
                'color' => strtoupper($data['color']),
                'status' => $overLimit ? Practitioner::STATUS_DRAFT : Practitioner::STATUS_ACTIVE,
                'position' => (int) Practitioner::query()->max('position') + 1,
            ]);

            $this->syncServices($organization, $practitioner, $data['service_ids'] ?? []);

            if ($data['copy_hours'] ?? true) {
                $this->copyMainSiteHours($organization, $practitioner);
            }

            Audit::record(
                'practitioner.created', 'user', actorId: auth()->id(), orgId: $organization->id,
                entityType: 'practitioner', entityId: $practitioner->id,
                diff: ['name' => $practitioner->name, 'status' => $practitioner->status],
            );

            return ['practitioner' => $practitioner, 'over_limit' => $overLimit];
        });
    }

    /**
     * @param  array{name: string, color: string, bio?: string|null, service_ids?: list<string>}  $data
     */
    public function update(Organization $organization, Practitioner $practitioner, array $data): void
    {
        DB::transaction(function () use ($organization, $practitioner, $data): void {
            $practitioner->fill(['name' => $data['name'], 'color' => strtoupper($data['color']), 'bio' => $data['bio'] ?? null]);
            $diff = collect($practitioner->getDirty())->map(fn ($new, string $key): array => [$practitioner->getOriginal($key), $new])->all();
            $practitioner->save();

            $before = $practitioner->services()->pluck('services.id')->sort()->values()->all();
            $this->syncServices($organization, $practitioner, $data['service_ids'] ?? []);
            $after = $practitioner->services()->pluck('services.id')->sort()->values()->all();

            if ($before !== $after) {
                $diff['services'] = [$before, $after];
            }

            if ($diff !== []) {
                Audit::record('practitioner.updated', 'user', actorId: auth()->id(), orgId: $organization->id, entityType: 'practitioner', entityId: $practitioner->id, diff: $diff);
            }
        });
    }

    /**
     * Met à jour uniquement les services proposés (utilisé par le praticien depuis son propre profil).
     *
     * @param  list<string>  $serviceIds
     */
    public function setServices(Organization $organization, Practitioner $practitioner, array $serviceIds): void
    {
        $before = $practitioner->services()->pluck('services.id')->sort()->values()->all();
        $this->syncServices($organization, $practitioner, $serviceIds);
        $after = $practitioner->services()->pluck('services.id')->sort()->values()->all();

        if ($before !== $after) {
            Audit::record('practitioner.updated', 'user', actorId: auth()->id(), orgId: $organization->id, entityType: 'practitioner', entityId: $practitioner->id, diff: ['services' => [$before, $after]]);
        }
    }

    public function delete(Organization $organization, Practitioner $practitioner): void
    {
        // La photo est une donnée personnelle : ses fichiers partent avec le praticien.
        app(PractitionerPhoto::class)->purge($practitioner);
        $practitioner->delete();

        Audit::record('practitioner.deleted', 'user', actorId: auth()->id(), orgId: $organization->id, entityType: 'practitioner', entityId: $practitioner->id, diff: ['name' => $practitioner->name]);
    }

    /**
     * Fait passer un brouillon en actif. Refusé (E8) tant que la limite de l'offre est atteinte.
     *
     * @throws PlanLimitReached
     */
    public function activate(Organization $organization, Practitioner $practitioner): void
    {
        if ($practitioner->status === Practitioner::STATUS_ACTIVE) {
            return;
        }

        if ($this->isAtLimit($organization)) {
            throw new PlanLimitReached((int) $this->limit($organization));
        }

        $practitioner->forceFill(['status' => Practitioner::STATUS_ACTIVE])->save();

        Audit::record('practitioner.activated', 'user', actorId: auth()->id(), orgId: $organization->id, entityType: 'practitioner', entityId: $practitioner->id);
    }

    /**
     * Planning hebdomadaire d'un praticien sur un site : jours 1 à 7 avec leurs plages « HH:MM ».
     *
     * @return array<int, list<array{start: string, end: string}>>
     */
    public function weekly(Practitioner $practitioner, Location $location): array
    {
        $week = array_fill_keys(range(1, 7), []);

        PractitionerSchedule::query()
            ->where('practitioner_id', $practitioner->id)->where('location_id', $location->id)
            ->orderBy('weekday')->orderBy('starts_at')->get()
            ->each(function (PractitionerSchedule $slot) use (&$week): void {
                $week[$slot->weekday][] = ['start' => substr($slot->starts_at, 0, 5), 'end' => substr($slot->ends_at, 0, 5)];
            });

        return $week;
    }

    /**
     * Remplace le planning du praticien sur un site. Un praticien ne peut pas être sur deux sites à la même heure.
     *
     * @param  array<int, list<array{start: string, end: string}>>  $week
     *
     * @throws InvalidArgumentException si une plage chevauche le planning du praticien sur un autre site
     */
    public function replaceSchedule(Organization $organization, Practitioner $practitioner, Location $location, array $week): void
    {
        $others = PractitionerSchedule::query()
            ->where('practitioner_id', $practitioner->id)->where('location_id', '!=', $location->id)->get();

        foreach ($week as $weekday => $ranges) {
            foreach ($ranges as $range) {
                foreach ($others->where('weekday', $weekday) as $other) {
                    if ($range['start'] < substr($other->ends_at, 0, 5) && $range['end'] > substr($other->starts_at, 0, 5)) {
                        throw new InvalidArgumentException('overlap_other_site');
                    }
                }
            }
        }

        $before = $this->weekly($practitioner, $location);

        DB::transaction(function () use ($organization, $practitioner, $location, $week): void {
            PractitionerSchedule::query()->where('practitioner_id', $practitioner->id)->where('location_id', $location->id)->delete();

            foreach ($week as $weekday => $ranges) {
                foreach ($ranges as $range) {
                    PractitionerSchedule::query()->create([
                        'org_id' => $organization->id, 'practitioner_id' => $practitioner->id, 'location_id' => $location->id,
                        'weekday' => $weekday, 'starts_at' => $range['start'], 'ends_at' => $range['end'],
                    ]);
                }
            }
        });

        Audit::record(
            'practitioner.schedule_updated', 'user', actorId: auth()->id(), orgId: $organization->id,
            entityType: 'practitioner', entityId: $practitioner->id, diff: ['location' => $location->id, 'before' => $before, 'after' => $week],
        );
    }

    /**
     * Un praticien est réservable s'il est actif, propose au moins un service et a au moins une plage de disponibilité.
     */
    public function isBookable(Practitioner $practitioner): bool
    {
        return $practitioner->status === Practitioner::STATUS_ACTIVE
            && $practitioner->services()->exists()
            && PractitionerSchedule::query()->where('practitioner_id', $practitioner->id)->exists();
    }

    /**
     * @param  list<string>  $serviceIds
     */
    private function syncServices(Organization $organization, Practitioner $practitioner, array $serviceIds): void
    {
        $practitioner->services()->sync(
            collect($serviceIds)->unique()->mapWithKeys(fn (string $id): array => [$id => ['org_id' => $organization->id]])->all(),
        );
    }

    /**
     * Planning initial : les horaires d'ouverture du site principal (modifiable ensuite).
     */
    private function copyMainSiteHours(Organization $organization, Practitioner $practitioner): void
    {
        $main = Location::query()->where('is_default', true)->first() ?? Location::query()->orderBy('created_at')->first();

        if ($main === null) {
            return;
        }

        BusinessHour::query()->where('location_id', $main->id)->orderBy('weekday')->orderBy('starts_at')->get()
            ->each(function (BusinessHour $hour) use ($organization, $practitioner, $main): void {
                PractitionerSchedule::query()->create([
                    'org_id' => $organization->id, 'practitioner_id' => $practitioner->id, 'location_id' => $main->id,
                    'weekday' => $hour->weekday, 'starts_at' => $hour->starts_at, 'ends_at' => $hour->ends_at,
                ]);
            });
    }
}
