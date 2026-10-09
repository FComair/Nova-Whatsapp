<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Reports\Support\Comparison;
use App\Modules\Reports\Support\ReportAccess;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Rapport « Praticiens » : charge de travail et occupation, sans jugement sur les personnes. Trois notions distinctes
 * (docs/23) — ne jamais les confondre :
 * - temps de travail PRÉVU : plages du planning du praticien, dans les horaires d'ouverture, indisponibilités et fermetures
 *   déduites, disponibilités exceptionnelles ajoutées ;
 * - temps RÉSERVÉ : durée des rendez-vous non annulés (à venir, terminés, absences) ;
 * - heures réellement travaillées : NON enregistrées par la plateforme, donc jamais affichées.
 * Taux d'occupation = temps réservé ÷ temps de travail prévu. Il peut dépasser 100 % si des rendez-vous ont été placés hors du
 * planning. Temps non réservé = prévu − réservé, jamais négatif. Jours de congé = jours où le planning prévoit du travail mais
 * où il ne reste aucune plage (indisponibilité ou site fermé) ; jours non travaillés = jours que le planning habituel ne prévoit pas.
 */
final class PractitionersReport
{
    private const MAX_TIME_OFF = 100;

    private const TOP_SERVICES = 5;

    public function __construct(
        private readonly ReportQuery $query,
        private readonly ReportAccess $access,
        private readonly AvailabilityService $availability,
        private readonly AppointmentsReport $appointments,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ReportFilters $filters, bool $withValues): array
    {
        $practitioners = $this->practitioners($filters);
        $now = collect($this->rows($filters, $practitioners, $withValues));
        $previous = collect($this->rows($filters->previous(), $practitioners, false));
        $rows = $now->map(function (array $row) use ($previous): array {
            $row['previous_assigned'] = $previous->firstWhere('id', $row['id'])['assigned'] ?? 0;

            return $row;
        })->values();

        $totals = $this->totals($now);
        $before = $this->totals($previous);

        return [
            'rows' => $rows->all(),
            'metrics' => [
                'assigned' => Comparison::amount($totals['assigned'], $before['assigned']),
                'completed' => Comparison::amount($totals['completed'], $before['completed']),
                'scheduled' => Comparison::amount($totals['scheduled'], $before['scheduled'], 'minutes'),
                'booked' => Comparison::amount($totals['booked'], $before['booked'], 'minutes'),
                'available' => Comparison::amount($totals['available'], $before['available'], 'minutes'),
                'utilization' => Comparison::rate($totals['utilization'], $before['utilization'], $totals['scheduled'] >= 120 ? 10 : 0, $before['scheduled'] >= 120 ? 10 : 0),
                'cancellation_rate' => Comparison::rate(Comparison::ratio($totals['cancelled'], $totals['assigned']), Comparison::ratio($before['cancelled'], $before['assigned']), $totals['assigned'], $before['assigned']),
                'no_show_rate' => Comparison::rate(Comparison::ratio($totals['no_show'], $totals['completed'] + $totals['no_show']), Comparison::ratio($before['no_show'], $before['completed'] + $before['no_show']), $totals['completed'] + $totals['no_show'], $before['completed'] + $before['no_show']),
            ],
            'heatmap' => $this->appointments->heatmap($filters),
            'time_off' => $this->timeOff($filters, $practitioners),
        ];
    }

    /**
     * Praticiens du rapport : ceux que l'utilisateur voit et que les filtres retiennent, actifs ou ayant eu des rendez-vous.
     *
     * @return Collection<int, stdClass>
     */
    private function practitioners(ReportFilters $filters): Collection
    {
        $own = $this->access->practitionerId();
        $withBookings = $this->query->bookings($filters, false)->select('b.practitioner_id');

        return DB::table('practitioners as p')->whereNull('p.deleted_at')
            ->where(fn ($q) => $q->where('p.status', Practitioner::STATUS_ACTIVE)->orWhereIn('p.id', $withBookings))
            ->when($own !== null, fn ($q) => $q->where('p.id', $own))
            ->when($filters->practitionerId !== null, fn ($q) => $q->where('p.id', $filters->practitionerId))
            ->orderBy('p.name')->get(['p.id', 'p.name', 'p.photo_updated_at']);
    }

    /**
     * Une ligne par praticien : rendez-vous, temps prévu et réservé, jours de congé, répartition des services.
     *
     * @param  Collection<int, stdClass>  $practitioners
     * @return list<array<string, mixed>>
     */
    private function rows(ReportFilters $filters, Collection $practitioners, bool $withValues): array
    {
        $duration = 'extract(epoch from (b.ends_at - b.starts_at)) / 60';
        $stats = $this->query->bookings($filters)->selectRaw("b.practitioner_id as id,
            count(*) as assigned,
            count(*) filter (where b.status = 'completed') as completed,
            count(*) filter (where b.status in ('cancelled_by_customer', 'cancelled_by_business')) as cancelled,
            count(*) filter (where b.status = 'no_show') as no_show,
            coalesce(sum({$duration}) filter (where b.status in ('confirmed', 'completed', 'no_show')), 0) as booked,
            coalesce(sum({$duration}) filter (where b.status = 'completed'), 0) as completed_minutes,
            coalesce(sum(b.price_cents) filter (where b.status in ('confirmed', 'completed', 'no_show')), 0) as value,
            coalesce(sum(b.amount_collected_cents), 0) as collected")
            ->groupBy('b.practitioner_id')->get()->keyBy('id');

        $services = $this->query->bookings($filters)->join('services as s', 's.id', '=', 'b.service_id')
            ->selectRaw('b.practitioner_id as practitioner, s.id as id, s.name as name, count(*) as n')
            ->groupBy('b.practitioner_id', 's.id', 's.name')->orderByDesc('n')->get()->groupBy('practitioner');

        $locations = $this->locations($filters);

        return $practitioners->map(function (object $practitioner) use ($filters, $stats, $services, $locations, $withValues): array {
            $row = $stats->get($practitioner->id);
            [$scheduled, $workingDays, $daysOff, $nonWorking] = $this->working($filters, (string) $practitioner->id, $locations);
            $booked = (int) round((float) ($row->booked ?? 0));
            $assigned = (int) ($row->assigned ?? 0);
            $completed = (int) ($row->completed ?? 0);
            $noShow = (int) ($row->no_show ?? 0);
            $cancelled = (int) ($row->cancelled ?? 0);

            return [
                'id' => (string) $practitioner->id,
                'name' => (string) $practitioner->name,
                'photo_version' => $practitioner->photo_updated_at === null ? null : (int) strtotime((string) $practitioner->photo_updated_at),
                'assigned' => $assigned,
                'completed' => $completed,
                'cancelled' => $cancelled,
                'no_show' => $noShow,
                'cancellation_rate' => Comparison::ratio($cancelled, $assigned),
                'no_show_rate' => Comparison::ratio($noShow, $completed + $noShow),
                'scheduled_minutes' => $scheduled,
                'booked_minutes' => $booked,
                'completed_minutes' => (int) round((float) ($row->completed_minutes ?? 0)),
                'available_minutes' => max(0, $scheduled - $booked),
                'utilization' => Comparison::ratio($booked, $scheduled),
                'working_days' => $workingDays,
                'days_off' => $daysOff,
                'non_working_days' => $nonWorking,
                'value_cents' => $withValues ? (int) ($row->value ?? 0) : null,
                'collected_cents' => $withValues ? (int) ($row->collected ?? 0) : null,
                'services' => ($services->get($practitioner->id) ?? collect())->take(self::TOP_SERVICES)
                    ->map(fn (object $service): array => ['id' => (string) $service->id, 'name' => (string) $service->name, 'count' => (int) $service->n])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * Sites sur lesquels on compte du temps de travail : ceux que l'utilisateur voit, ou celui du filtre.
     *
     * @return Collection<int, Location>
     */
    private function locations(ReportFilters $filters): Collection
    {
        $allowed = $this->access->locationIds();

        return Location::query()
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed === [] ? ['00000000-0000-0000-0000-000000000000'] : $allowed))
            ->when($filters->locationId !== null, fn ($q) => $q->where('id', $filters->locationId))
            ->get();
    }

    /**
     * Temps de travail prévu, jours travaillés, jours de congé et jours non travaillés d'un praticien sur la période.
     *
     * @param  Collection<int, Location>  $locations
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function working(ReportFilters $filters, string $practitionerId, Collection $locations): array
    {
        $practitioner = Practitioner::query()->find($practitionerId);

        if ($practitioner === null) {
            return [0, 0, 0, 0];
        }

        $sites = $locations->filter(fn (Location $location): bool => DB::table('practitioner_schedules')->where('practitioner_id', $practitionerId)->where('location_id', $location->id)->exists());
        $perDay = [];

        foreach ($sites as $location) {
            foreach ($this->availability->workingDays($location, $practitioner, $filters->from->toDateString(), $filters->to->toDateString()) as $date => $day) {
                $perDay[$date]['minutes'] = ($perDay[$date]['minutes'] ?? 0) + $day['minutes'];
                $perDay[$date]['scheduled'] = ($perDay[$date]['scheduled'] ?? false) || $day['scheduled'];
            }
        }

        if ($perDay === []) {
            return [0, 0, 0, $filters->days()];
        }

        $minutes = array_sum(array_column($perDay, 'minutes'));
        $worked = count(array_filter($perDay, fn (array $day): bool => $day['minutes'] > 0));
        $off = count(array_filter($perDay, fn (array $day): bool => $day['scheduled'] && $day['minutes'] === 0));
        $non = count(array_filter($perDay, fn (array $day): bool => ! $day['scheduled']));

        return [$minutes, $worked, $off, $non];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{assigned: int, completed: int, cancelled: int, no_show: int, scheduled: int, booked: int, available: int, utilization: float|null}
     */
    private function totals(Collection $rows): array
    {
        $scheduled = (int) $rows->sum('scheduled_minutes');
        $booked = (int) $rows->sum('booked_minutes');

        return [
            'assigned' => (int) $rows->sum('assigned'),
            'completed' => (int) $rows->sum('completed'),
            'cancelled' => (int) $rows->sum('cancelled'),
            'no_show' => (int) $rows->sum('no_show'),
            'scheduled' => $scheduled,
            'booked' => $booked,
            'available' => (int) $rows->sum('available_minutes'),
            'utilization' => Comparison::ratio($booked, $scheduled),
        ];
    }

    /**
     * Indisponibilités (congés, absences) qui touchent la période. Le motif n'est pas repris : il peut être personnel.
     *
     * @param  Collection<int, stdClass>  $practitioners
     * @return list<array{practitioner_id: string, practitioner: string, from: string, to: string, days: int}>
     */
    private function timeOff(ReportFilters $filters, Collection $practitioners): array
    {
        $tz = $filters->timezone;
        $start = $filters->from->setTimezone($tz)->startOfDay()->utc();
        $end = $filters->to->setTimezone($tz)->addDay()->startOfDay()->utc();
        $names = $practitioners->pluck('name', 'id');

        return DB::table('practitioner_time_off as t')->where('t.kind', 'unavailable')->whereIn('t.practitioner_id', $names->keys()->all())
            ->where('t.starts_at', '<', $end)->where('t.ends_at', '>', $start)->orderBy('t.starts_at')->limit(self::MAX_TIME_OFF)->get(['t.practitioner_id', 't.starts_at', 't.ends_at'])
            ->map(function (object $off) use ($names, $filters, $tz): array {
                $from = CarbonImmutable::parse((string) $off->starts_at)->setTimezone($tz)->startOfDay();
                // Une indisponibilité qui finit à minuit pile se termine la veille.
                $to = CarbonImmutable::parse((string) $off->ends_at)->setTimezone($tz)->subSecond()->startOfDay();
                $clippedFrom = $from->max($filters->from);
                $clippedTo = $to->min($filters->to);

                return [
                    'practitioner_id' => (string) $off->practitioner_id,
                    'practitioner' => (string) $names->get($off->practitioner_id),
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'days' => max(1, (int) $clippedFrom->diffInDays($clippedTo) + 1),
                ];
            })->all();
    }
}
