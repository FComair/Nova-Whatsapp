<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\Buckets;
use App\Modules\Reports\Support\Comparison;
use App\Modules\Reports\Support\ReportFilters;
use Carbon\CarbonImmutable;

/**
 * Rapport « Rendez-vous » : demande, issues et répartition. Définitions (docs/23) :
 * - réservés : tous les rendez-vous réels de la période, annulés compris (hors blocages en attente ou expirés) ;
 * - à venir : confirmés dont l'heure n'est pas encore passée ; en attente de résultat : confirmés dont l'heure est passée mais
 *   que personne n'a marqués terminés ni absents (ils ne sont comptés ni honorés ni absents : on ne devine pas) ;
 * - échus : terminés + absents + en attente de résultat (le rendez-vous a eu lieu ou aurait dû) ;
 * - taux d'annulation : annulés ÷ réservés ; taux d'absence : absents ÷ échus ; taux de réalisation : terminés ÷ échus ;
 * - moyenne par jour : rendez-vous non annulés ÷ jours de la période ;
 * - déplacés : rendez-vous dont la date ou l'heure a changé au moins une fois.
 */
final class AppointmentsReport
{
    private const MAX_SERVICES = 10;

    public function __construct(private readonly ReportQuery $query) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ReportFilters $filters, bool $withValues): array
    {
        $now = CarbonImmutable::now();
        $current = $this->snapshot($filters, $now);
        $previous = $this->snapshot($filters->previous(), $now);

        return [
            'metrics' => $this->metrics($current, $previous, $filters),
            'series' => $this->series($filters, $now),
            'statuses' => [
                ['key' => 'completed', 'count' => $current['completed']],
                ['key' => 'upcoming', 'count' => $current['upcoming']],
                ['key' => 'awaiting', 'count' => $current['awaiting']],
                ['key' => 'no_show', 'count' => $current['no_show']],
                ['key' => 'cancelled', 'count' => $current['cancelled']],
            ],
            'heatmap' => $this->heatmap($filters),
            'sources' => $this->sources($filters, $current['total']),
            'services' => $this->services($filters, $withValues),
            'service_sources' => $this->serviceSources($filters),
        ];
    }

    /**
     * @return array{total: int, completed: int, upcoming: int, awaiting: int, cancelled: int, no_show: int, rescheduled: int}
     */
    public function snapshot(ReportFilters $filters, CarbonImmutable $now): array
    {
        $at = $now->utc()->toIso8601String();
        $row = $this->query->bookings($filters)->selectRaw("
            count(*) as total,
            count(*) filter (where b.status = 'completed') as completed,
            count(*) filter (where b.status = 'confirmed' and b.starts_at >= ?) as upcoming,
            count(*) filter (where b.status = 'confirmed' and b.starts_at < ?) as awaiting,
            count(*) filter (where b.status in ('cancelled_by_customer', 'cancelled_by_business')) as cancelled,
            count(*) filter (where b.status = 'no_show') as no_show,
            count(*) filter (where exists (
                select 1 from booking_events e
                where e.booking_id = b.id and e.action = 'moved'
                  and (e.diff->'from'->>'starts_at')::timestamptz <> (e.diff->'to'->>'starts_at')::timestamptz
            )) as rescheduled
        ", [$at, $at])->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'completed' => (int) ($row->completed ?? 0),
            'upcoming' => (int) ($row->upcoming ?? 0),
            'awaiting' => (int) ($row->awaiting ?? 0),
            'cancelled' => (int) ($row->cancelled ?? 0),
            'no_show' => (int) ($row->no_show ?? 0),
            'rescheduled' => (int) ($row->rescheduled ?? 0),
        ];
    }

    /**
     * @param  array{total: int, completed: int, upcoming: int, awaiting: int, cancelled: int, no_show: int, rescheduled: int}  $now
     * @param  array{total: int, completed: int, upcoming: int, awaiting: int, cancelled: int, no_show: int, rescheduled: int}  $before
     * @return array<string, array<string, mixed>>
     */
    private function metrics(array $now, array $before, ReportFilters $filters): array
    {
        $due = $now['completed'] + $now['no_show'] + $now['awaiting'];
        $dueBefore = $before['completed'] + $before['no_show'] + $before['awaiting'];
        $days = $filters->days();
        $daysBefore = $filters->previous()->days();
        $perDay = ($now['total'] - $now['cancelled']) / $days;
        $perDayBefore = ($before['total'] - $before['cancelled']) / $daysBefore;

        return [
            'total' => Comparison::amount($now['total'], $before['total']),
            'completed' => Comparison::amount($now['completed'], $before['completed']),
            // Tournés vers l'avenir : sans équivalent sur la période précédente (déjà passée).
            'upcoming' => Comparison::amount($now['upcoming'], null),
            'awaiting' => Comparison::amount($now['awaiting'], null),
            'cancelled' => Comparison::amount($now['cancelled'], $before['cancelled']),
            'no_show' => Comparison::amount($now['no_show'], $before['no_show']),
            'rescheduled' => Comparison::amount($now['rescheduled'], $before['rescheduled']),
            'cancellation_rate' => Comparison::rate(Comparison::ratio($now['cancelled'], $now['total']), Comparison::ratio($before['cancelled'], $before['total']), $now['total'], $before['total']),
            'no_show_rate' => Comparison::rate(Comparison::ratio($now['no_show'], $due), Comparison::ratio($before['no_show'], $dueBefore), $due, $dueBefore),
            'completion_rate' => Comparison::rate(Comparison::ratio($now['completed'], $due), Comparison::ratio($before['completed'], $dueBefore), $due, $dueBefore),
            'avg_per_day' => Comparison::amount(round($perDay, 1), round($perDayBefore, 1)),
            'due' => Comparison::amount($due, $dueBefore),
        ];
    }

    /**
     * @return array{granularity: string, buckets: list<array<string, mixed>>}
     */
    private function series(ReportFilters $filters, CarbonImmutable $now): array
    {
        $granularity = Buckets::granularity($filters);
        $bucket = Buckets::sql($granularity, ReportQuery::LOCAL);
        $at = $now->utc()->toIso8601String();

        $rows = $this->query->bookings($filters)
            ->selectRaw("{$bucket} as bucket,
                count(*) as total,
                count(*) filter (where b.status = 'completed') as completed,
                count(*) filter (where b.status = 'confirmed' and b.starts_at >= ?) as upcoming,
                count(*) filter (where b.status = 'confirmed' and b.starts_at < ?) as awaiting,
                count(*) filter (where b.status = 'no_show') as no_show,
                count(*) filter (where b.status in ('cancelled_by_customer', 'cancelled_by_business')) as cancelled", [$at, $at])
            ->groupByRaw($bucket)->get()->keyBy(fn (object $row): string => (string) $row->bucket);

        $buckets = [];

        foreach (Buckets::keys($filters, $granularity) as $key) {
            $row = $rows->get($key);
            $buckets[] = [
                'key' => $key,
                'total' => (int) ($row->total ?? 0),
                'completed' => (int) ($row->completed ?? 0),
                'upcoming' => (int) ($row->upcoming ?? 0),
                'awaiting' => (int) ($row->awaiting ?? 0),
                'no_show' => (int) ($row->no_show ?? 0),
                'cancelled' => (int) ($row->cancelled ?? 0),
            ];
        }

        return ['granularity' => $granularity, 'buckets' => $buckets];
    }

    /**
     * Rendez-vous non annulés par jour de la semaine (1 = lundi) et heure de début (heure locale du site).
     *
     * @return array{cells: array<int, array<int, int>>, max: int, total: int, first_hour: int, last_hour: int}
     */
    public function heatmap(ReportFilters $filters): array
    {
        $local = ReportQuery::LOCAL;
        $rows = $this->query->bookings($filters)->whereIn('b.status', ReportQuery::ACTIVE)
            ->selectRaw("extract(isodow from {$local})::int as weekday, extract(hour from {$local})::int as hour, count(*) as n")
            ->groupByRaw('1, 2')->get();

        $cells = [];

        foreach (range(1, 7) as $weekday) {
            $cells[$weekday] = array_fill(0, 24, 0);
        }

        foreach ($rows as $row) {
            $cells[(int) $row->weekday][(int) $row->hour] = (int) $row->n;
        }

        $total = (int) $rows->sum('n');
        $hours = $rows->pluck('hour')->map(fn ($hour): int => (int) $hour);

        return [
            'cells' => $cells,
            'max' => (int) ($rows->max('n') ?? 0),
            'total' => $total,
            'first_hour' => (int) ($hours->min() ?? 8),
            'last_hour' => (int) ($hours->max() ?? 18),
        ];
    }

    /**
     * @return list<array{key: string, count: int, share: float|null}>
     */
    private function sources(ReportFilters $filters, int $total): array
    {
        return $this->query->bookings($filters)->selectRaw('b.source as source, count(*) as n')->groupBy('b.source')->orderByDesc('n')->get()
            ->map(fn (object $row): array => ['key' => (string) $row->source, 'count' => (int) $row->n, 'share' => Comparison::ratio((int) $row->n, $total)])->all();
    }

    /**
     * Les services les plus demandés (les autres sont regroupés).
     *
     * @return list<array<string, mixed>>
     */
    private function services(ReportFilters $filters, bool $withValues): array
    {
        $rows = $this->query->bookings($filters)->join('services as s', 's.id', '=', 'b.service_id')
            ->selectRaw("s.id as id, s.name as name, count(*) as n,
                count(*) filter (where b.status in ('cancelled_by_customer', 'cancelled_by_business')) as cancelled,
                coalesce(sum(b.price_cents) filter (where b.status in ('confirmed', 'completed', 'no_show')), 0) as value")
            ->groupBy('s.id', 's.name')->orderByDesc('n')->orderBy('s.name')->get();

        $services = $rows->take(self::MAX_SERVICES)->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'count' => (int) $row->n,
            'cancelled' => (int) $row->cancelled,
            'value_cents' => $withValues ? (int) $row->value : null,
        ])->values()->all();

        $rest = $rows->slice(self::MAX_SERVICES);

        if ($rest->isNotEmpty()) {
            $services[] = [
                'id' => null,
                'name' => null,
                'count' => (int) $rest->sum('n'),
                'cancelled' => (int) $rest->sum('cancelled'),
                'value_cents' => $withValues ? (int) $rest->sum('value') : null,
            ];
        }

        return $services;
    }

    /**
     * Rendez-vous par service et par source (les services les plus demandés).
     *
     * @return list<array{id: string, name: string, sources: array<string, int>}>
     */
    private function serviceSources(ReportFilters $filters): array
    {
        $rows = $this->query->bookings($filters)->join('services as s', 's.id', '=', 'b.service_id')
            ->selectRaw('s.id as id, s.name as name, b.source as source, count(*) as n')
            ->groupBy('s.id', 's.name', 'b.source')->get();

        $top = $rows->groupBy('id')->map(fn ($group): int => (int) $group->sum('n'))->sortDesc()->take(self::MAX_SERVICES)->keys();

        return $top->map(function ($id) use ($rows): array {
            $group = $rows->where('id', $id);

            return [
                'id' => (string) $id,
                'name' => (string) $group->first()->name,
                'sources' => $group->mapWithKeys(fn (object $row): array => [(string) $row->source => (int) $row->n])->all(),
            ];
        })->values()->all();
    }
}
