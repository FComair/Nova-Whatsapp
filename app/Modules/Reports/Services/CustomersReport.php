<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\Buckets;
use App\Modules\Reports\Support\Comparison;
use App\Modules\Reports\Support\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rapport « Clients » : uniquement des chiffres agrégés, jamais un nom, un numéro ou une adresse. Définitions (docs/23) :
 * - clients : clients de l'établissement, hors archivés et effacés (limités aux sites et praticiens que l'utilisateur voit) ;
 * - nouveaux : clients créés pendant la période ; actifs : clients ayant au moins un rendez-vous non annulé pendant la période ;
 * - fidèles (« returning ») : clients actifs qui avaient déjà eu un rendez-vous non annulé avant la période ;
 * - taux de retour : fidèles ÷ actifs ; rétention : clients actifs la période précédente qui le sont aussi cette période ;
 * - rendez-vous par client : rendez-vous non annulés de la période ÷ clients actifs ;
 * - sans rendez-vous récent : clients qui ont déjà eu un rendez-vous mais aucun depuis 90 jours, ni à venir.
 * Le filtre de statut ne s'applique pas ici : l'activité d'un client se juge sur ses rendez-vous non annulés.
 */
final class CustomersReport
{
    /** Au-delà de ce délai sans rendez-vous, un client est dit « sans rendez-vous récent ». */
    public const INACTIVE_DAYS = 90;

    public function __construct(private readonly ReportQuery $query) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ReportFilters $filters): array
    {
        $now = CarbonImmutable::now();
        $previous = $filters->previous();
        $current = $this->period($filters);
        $before = $this->period($previous);
        $newNow = $this->newCustomers($filters);
        $newBefore = $this->newCustomers($previous);
        $retained = $this->retained($filters, $previous);

        $perCustomer = $current['active'] > 0 ? round($current['appointments'] / $current['active'], 2) : null;
        $perCustomerBefore = $before['active'] > 0 ? round($before['appointments'] / $before['active'], 2) : null;

        return [
            'inactive_days' => self::INACTIVE_DAYS,
            'metrics' => [
                'customers_total' => Comparison::amount($this->customers($filters)->count(), null),
                'new' => Comparison::amount($newNow, $newBefore),
                'active' => Comparison::amount($current['active'], $before['active']),
                'returning' => Comparison::amount($current['returning'], $before['returning']),
                'with_upcoming' => Comparison::amount($this->withUpcoming($filters, $now), null),
                'inactive' => Comparison::amount($this->inactive($filters, $now), null),
                'avg_per_customer' => Comparison::amount($perCustomer ?? 0, $perCustomerBefore),
                'returning_rate' => Comparison::rate(Comparison::ratio($current['returning'], $current['active']), Comparison::ratio($before['returning'], $before['active']), $current['active'], $before['active']),
                'retention' => Comparison::rate($retained['rate'], null, $retained['of'], 0),
            ],
            'retention_base' => $retained['of'],
            'series' => $this->series($filters),
            'by_service' => $this->preferredServices($filters),
            'by_source' => $this->firstSources($filters),
            'by_location' => $this->mainLocations($filters),
        ];
    }

    /** Clients visibles : ni archivés ni effacés, et — si l'accès ou un filtre le demande — liés aux sites/praticiens/services choisis. */
    private function customers(ReportFilters $filters): Builder
    {
        $query = DB::table('customers as c')->whereNull('c.deleted_at')->whereNull('c.anonymized_at')->where('c.status', '!=', 'archived');

        return $query->whereExists($this->query->filtered($this->query->scoped()->select(DB::raw('1'))->whereColumn('b.customer_id', 'c.id'), $filters, false));
    }

    /** Rendez-vous non annulés de la période (filtres communs, sans filtre de statut). */
    private function activeBookings(ReportFilters $filters): Builder
    {
        return $this->query->bookings($filters, false)->whereIn('b.status', ReportQuery::ACTIVE);
    }

    /**
     * @return array{active: int, returning: int, appointments: int}
     */
    private function period(ReportFilters $filters): array
    {
        $row = $this->activeBookings($filters)->selectRaw('
            count(distinct b.customer_id) as active,
            count(*) as appointments,
            count(distinct b.customer_id) filter (where exists (
                select 1 from bookings e join locations el on el.id = e.location_id
                where e.customer_id = b.customer_id and e.status in (\'confirmed\', \'completed\', \'no_show\')
                  and (e.starts_at at time zone el.timezone)::date < ?
            )) as returning', [$filters->from->toDateString()])->first();

        return ['active' => (int) ($row->active ?? 0), 'returning' => (int) ($row->returning ?? 0), 'appointments' => (int) ($row->appointments ?? 0)];
    }

    private function newCustomers(ReportFilters $filters): int
    {
        return $this->customers($filters)
            ->whereRaw('(c.created_at at time zone ?)::date between ? and ?', [$filters->timezone, $filters->from->toDateString(), $filters->to->toDateString()])
            ->count();
    }

    private function withUpcoming(ReportFilters $filters, CarbonImmutable $now): int
    {
        return (int) $this->query->filtered($this->query->scoped(), $filters, false)
            ->where('b.status', 'confirmed')->where('b.starts_at', '>=', $now->utc()->toIso8601String())
            ->distinct()->count('b.customer_id');
    }

    private function inactive(ReportFilters $filters, CarbonImmutable $now): int
    {
        $limit = $now->subDays(self::INACTIVE_DAYS)->utc()->toIso8601String();

        return $this->customers($filters)
            ->whereExists(fn (Builder $q) => $q->from('bookings as x')->selectRaw('1')->whereColumn('x.customer_id', 'c.id')->whereIn('x.status', ReportQuery::ACTIVE))
            ->whereNotExists(fn (Builder $q) => $q->from('bookings as y')->selectRaw('1')->whereColumn('y.customer_id', 'c.id')->whereIn('y.status', ReportQuery::ACTIVE)->where('y.starts_at', '>=', $limit))
            ->count();
    }

    /**
     * Part des clients actifs la période précédente qui le sont encore cette période.
     *
     * @return array{rate: float|null, of: int}
     */
    private function retained(ReportFilters $filters, ReportFilters $previous): array
    {
        $before = $this->activeBookings($previous)->distinct()->pluck('b.customer_id')->all();

        if ($before === []) {
            return ['rate' => null, 'of' => 0];
        }

        $now = array_flip($this->activeBookings($filters)->distinct()->pluck('b.customer_id')->all());
        $kept = count(array_filter($before, fn ($id): bool => isset($now[$id])));

        return ['rate' => $kept / count($before), 'of' => count($before)];
    }

    /**
     * @return array{granularity: string, buckets: list<array{key: string, new: int, active: int}>}
     */
    private function series(ReportFilters $filters): array
    {
        $granularity = Buckets::granularity($filters);
        $active = $this->activeBookings($filters)
            ->selectRaw(Buckets::sql($granularity, ReportQuery::LOCAL).' as bucket, count(distinct b.customer_id) as n')->groupByRaw('1')->pluck('n', 'bucket');

        $created = '(c.created_at at time zone '.DB::getPdo()->quote($filters->timezone).')';
        $new = $this->customers($filters)
            ->whereRaw("{$created}::date between ? and ?", [$filters->from->toDateString(), $filters->to->toDateString()])
            ->selectRaw(Buckets::sql($granularity, $created).' as bucket, count(*) as n')->groupByRaw('1')->pluck('n', 'bucket');

        return [
            'granularity' => $granularity,
            'buckets' => array_map(fn (string $key): array => ['key' => $key, 'new' => (int) ($new[$key] ?? 0), 'active' => (int) ($active[$key] ?? 0)], Buckets::keys($filters, $granularity)),
        ];
    }

    /**
     * Répartition des clients actifs selon le service qu'ils réservent le plus.
     *
     * @return list<array{id: string, name: string, count: int}>
     */
    private function preferredServices(ReportFilters $filters): array
    {
        return $this->distribution($filters, 'service_id', 'services', 'name');
    }

    /**
     * Répartition des clients actifs selon le site où ils réservent le plus ; absente s'il n'y a qu'un site.
     *
     * @return list<array{id: string, name: string, count: int}>|null
     */
    private function mainLocations(ReportFilters $filters): ?array
    {
        $rows = $this->distribution($filters, 'location_id', 'locations', 'name');

        return count($rows) > 1 ? $rows : null;
    }

    /**
     * @return list<array{id: string, name: string, count: int}>
     */
    private function distribution(ReportFilters $filters, string $column, string $table, string $name): array
    {
        $active = $this->activeBookings($filters)->select('b.customer_id')->distinct();
        $rows = DB::select("
            with active as ({$active->toSql()}),
            top as (
                select distinct on (x.customer_id) x.customer_id, x.{$column} as ref
                from (
                    select customer_id, {$column}, count(*) as n, max(starts_at) as last_at
                    from bookings
                    where status in ('confirmed', 'completed', 'no_show') and customer_id in (select customer_id from active)
                    group by customer_id, {$column}
                ) x
                order by x.customer_id, x.n desc, x.last_at desc
            )
            select t.id as id, t.{$name} as name, count(*) as n from top join {$table} t on t.id = top.ref
            group by t.id, t.{$name} order by n desc, t.{$name} limit 12
        ", $active->getBindings());

        return array_map(fn (object $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name, 'count' => (int) $row->n], $rows);
    }

    /**
     * Répartition des clients actifs selon le canal de leur premier rendez-vous.
     *
     * @return list<array{key: string, count: int}>
     */
    private function firstSources(ReportFilters $filters): array
    {
        $active = $this->activeBookings($filters)->select('b.customer_id')->distinct();
        $rows = DB::select("
            with active as ({$active->toSql()}),
            firsts as (
                select distinct on (customer_id) customer_id, source from bookings
                where status in ('confirmed', 'completed', 'no_show') and customer_id in (select customer_id from active)
                order by customer_id, starts_at asc
            )
            select source, count(*) as n from firsts group by source order by n desc
        ", $active->getBindings());

        return array_map(fn (object $row): array => ['key' => (string) $row->source, 'count' => (int) $row->n], $rows);
    }
}
