<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\ReportAccess;
use App\Modules\Reports\Support\ReportFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Requête de base de tous les rapports : les rendez-vous de la période (jour local du site), limités à ce que l'utilisateur a
 * le droit de voir, puis aux filtres choisis. L'isolation entre établissements reste assurée par la base (RLS).
 *
 * Seuls les rendez-vous réels comptent : un blocage « en attente » ou expiré n'a jamais été un rendez-vous.
 */
final class ReportQuery
{
    /** Rendez-vous réels. */
    public const COUNTED = ['confirmed', 'completed', 'no_show', 'cancelled_by_customer', 'cancelled_by_business'];

    /** Rendez-vous non annulés : ceux qui occupent ou ont occupé du temps. */
    public const ACTIVE = ['confirmed', 'completed', 'no_show'];

    public const CANCELLED = ['cancelled_by_customer', 'cancelled_by_business'];

    /** Jour et heure locaux du site de chaque rendez-vous. */
    public const LOCAL = '(b.starts_at at time zone l.timezone)';

    public function __construct(private readonly ReportAccess $access) {}

    /**
     * Rendez-vous de la période, avec les filtres. `$statuses` : appliquer ou non le filtre de statut (les rapports clients
     * jugent l'activité sur tous les rendez-vous non annulés, quel que soit le statut filtré).
     */
    public function bookings(ReportFilters $filters, bool $statuses = true): Builder
    {
        [$low, $high] = $filters->utcBounds();

        $query = $this->scoped()
            ->where('b.starts_at', '>=', $low)->where('b.starts_at', '<', $high)
            ->whereRaw('('.self::LOCAL.')::date between ? and ?', [$filters->from->toDateString(), $filters->to->toDateString()]);

        return $this->filtered($query, $filters, $statuses);
    }

    /** Tous les rendez-vous réels visibles par l'utilisateur, sans période ni filtre. */
    public function scoped(): Builder
    {
        $query = DB::table('bookings as b')
            ->join('locations as l', 'l.id', '=', 'b.location_id')
            ->whereIn('b.status', self::COUNTED);

        $locations = $this->access->locationIds();

        if ($locations !== null) {
            $query->whereIn('b.location_id', $locations === [] ? ['00000000-0000-0000-0000-000000000000'] : $locations);
        }

        if (($own = $this->access->practitionerId()) !== null) {
            $query->where('b.practitioner_id', $own);
        }

        return $query;
    }

    /** Filtres de site, praticien, service, source (et statut) appliqués à une requête de rendez-vous. */
    public function filtered(Builder $query, ReportFilters $filters, bool $statuses = true): Builder
    {
        $query
            ->when($filters->locationId !== null, fn (Builder $q) => $q->where('b.location_id', $filters->locationId))
            ->when($filters->practitionerId !== null, fn (Builder $q) => $q->where('b.practitioner_id', $filters->practitionerId))
            ->when($filters->serviceId !== null, fn (Builder $q) => $q->where('b.service_id', $filters->serviceId))
            ->when($filters->source !== null, fn (Builder $q) => $q->where('b.source', $filters->source));

        if ($statuses && $filters->statuses !== []) {
            $query->whereIn('b.status', $this->statusValues($filters->statuses));
        }

        return $query;
    }

    /**
     * @param  list<string>  $statuses
     * @return list<string>
     */
    public function statusValues(array $statuses): array
    {
        return array_values(array_unique(array_merge(...array_map(
            fn (string $status): array => $status === 'cancelled' ? self::CANCELLED : [$status],
            $statuses,
        ))));
    }
}
