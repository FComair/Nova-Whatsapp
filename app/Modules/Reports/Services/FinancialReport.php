<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\Buckets;
use App\Modules\Reports\Support\Comparison;
use App\Modules\Reports\Support\ReportAccess;
use App\Modules\Reports\Support\ReportFilters;
use Illuminate\Database\Query\Builder;

/**
 * Rapport « Finances ». Uniquement ce que la plateforme enregistre : le prix du rendez-vous (au moment de la réservation) et
 * ce qui a été encaissé (statut de paiement, montant encaissé, moyen). Définitions (docs/23) :
 * - valeur des rendez-vous : prix des rendez-vous non annulés (à venir, terminés, absences) ; ce n'est PAS de l'argent reçu ;
 * - valeur des terminés : prix des rendez-vous marqués terminés ;
 * - encaissé : montants enregistrés comme encaissés, rattachés au jour du rendez-vous (la date du paiement n'est pas conservée) ;
 * - à encaisser : prix moins encaissé, sur les rendez-vous terminés dont le paiement est « non payé » ou « partiel » ;
 * - offert ou abandonné : prix moins encaissé, sur les rendez-vous terminés marqués « offert » ou « abandonné » ;
 * - valeur moyenne : valeur des rendez-vous ÷ nombre de rendez-vous non annulés.
 * Non disponibles car non enregistrés : remboursements, remises, TVA ventilée, date de paiement.
 * Un établissement a normalement une seule devise ; s'il en avait plusieurs, seule la plus utilisée de la période est chiffrée.
 */
final class FinancialReport
{
    public function __construct(private readonly ReportQuery $query, private readonly ReportAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ReportFilters $filters): array
    {
        $currency = $this->currency($filters);
        $other = $this->query->bookings($filters)->where('b.currency', '!=', $currency)->count();
        $now = $this->totals($filters, $currency);
        $before = $this->totals($filters->previous(), $currency);

        return [
            'currency' => $currency,
            'excluded_other_currency' => $other,
            'metrics' => [
                'booked_value' => Comparison::amount($now['booked'], $before['booked'], 'money'),
                'completed_value' => Comparison::amount($now['completed'], $before['completed'], 'money'),
                'collected' => Comparison::amount($now['collected'], $before['collected'], 'money'),
                'outstanding' => Comparison::amount($now['outstanding'], $before['outstanding'], 'money'),
                'waived' => Comparison::amount($now['waived'], $before['waived'], 'money'),
                'no_show_value' => Comparison::amount($now['no_show'], $before['no_show'], 'money'),
                'cancelled_value' => Comparison::amount($now['cancelled'], $before['cancelled'], 'money'),
                'avg_value' => Comparison::amount($now['active'] > 0 ? (int) round($now['booked'] / $now['active']) : 0, $before['active'] > 0 ? (int) round($before['booked'] / $before['active']) : 0, 'money'),
                'collection_rate' => Comparison::rate(Comparison::ratio($now['collected_completed'], $now['completed']), Comparison::ratio($before['collected_completed'], $before['completed']), $now['completed_count'], $before['completed_count']),
            ],
            'series' => $this->series($filters, $currency),
            'services' => $this->by($filters, $currency, 's.id', 's.name', 'services as s', 's.id', 'b.service_id'),
            'practitioners' => $this->access->canCompareStaff() ? $this->practitioners($filters, $currency) : null,
            'locations' => $this->locations($filters, $currency),
            'methods' => $this->methods($filters, $currency),
            'payment_statuses' => $this->paymentStatuses($filters, $currency),
            'unavailable' => ['refunds', 'discounts', 'payment_date', 'vat_breakdown'],
        ];
    }

    private function currency(ReportFilters $filters): string
    {
        $found = $this->query->bookings($filters)->select('b.currency')->groupBy('b.currency')->orderByRaw('count(*) desc')->value('currency');

        return is_string($found) ? trim($found) : 'EUR';
    }

    private function base(ReportFilters $filters, string $currency): Builder
    {
        return $this->query->bookings($filters)->where('b.currency', $currency);
    }

    /**
     * @return array<string, int>
     */
    public function totals(ReportFilters $filters, string $currency): array
    {
        $row = $this->base($filters, $currency)->selectRaw("
            coalesce(sum(b.price_cents) filter (where b.status in ('confirmed', 'completed', 'no_show')), 0) as booked,
            count(*) filter (where b.status in ('confirmed', 'completed', 'no_show')) as active,
            coalesce(sum(b.price_cents) filter (where b.status = 'completed'), 0) as completed,
            count(*) filter (where b.status = 'completed') as completed_count,
            coalesce(sum(b.amount_collected_cents) filter (where b.status = 'completed'), 0) as collected_completed,
            coalesce(sum(b.price_cents) filter (where b.status = 'no_show'), 0) as no_show,
            coalesce(sum(b.price_cents) filter (where b.status in ('cancelled_by_customer', 'cancelled_by_business')), 0) as cancelled,
            coalesce(sum(b.amount_collected_cents), 0) as collected,
            coalesce(sum(greatest(b.price_cents - b.amount_collected_cents, 0)) filter (where b.status = 'completed' and b.payment_status in ('unpaid', 'partially_paid')), 0) as outstanding,
            coalesce(sum(greatest(b.price_cents - b.amount_collected_cents, 0)) filter (where b.status = 'completed' and b.payment_status in ('waived', 'written_off')), 0) as waived
        ")->first();

        return array_map('intval', (array) $row);
    }

    /**
     * @return array{granularity: string, buckets: list<array{key: string, value: int, collected: int}>}
     */
    private function series(ReportFilters $filters, string $currency): array
    {
        $granularity = Buckets::granularity($filters);
        $bucket = Buckets::sql($granularity, ReportQuery::LOCAL);
        $rows = $this->base($filters, $currency)->selectRaw("{$bucket} as bucket,
            coalesce(sum(b.price_cents) filter (where b.status in ('confirmed', 'completed', 'no_show')), 0) as value,
            coalesce(sum(b.amount_collected_cents), 0) as collected")->groupByRaw($bucket)->get()->keyBy(fn (object $row): string => (string) $row->bucket);

        return [
            'granularity' => $granularity,
            'buckets' => array_map(fn (string $key): array => ['key' => $key, 'value' => (int) ($rows->get($key)->value ?? 0), 'collected' => (int) ($rows->get($key)->collected ?? 0)], Buckets::keys($filters, $granularity)),
        ];
    }

    /**
     * @return list<array{id: string, name: string, count: int, value: int, collected: int}>
     */
    private function by(ReportFilters $filters, string $currency, string $id, string $name, string $table, string $on, string $column): array
    {
        return $this->base($filters, $currency)->join($table, $on, '=', $column)
            ->selectRaw("{$id} as id, {$name} as name,
                count(*) filter (where b.status in ('confirmed', 'completed', 'no_show')) as n,
                coalesce(sum(b.price_cents) filter (where b.status in ('confirmed', 'completed', 'no_show')), 0) as value,
                coalesce(sum(b.amount_collected_cents), 0) as collected")
            ->groupByRaw("{$id}, {$name}")->orderByDesc('value')->orderByRaw($name)->get()
            ->map(fn (object $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name, 'count' => (int) $row->n, 'value' => (int) $row->value, 'collected' => (int) $row->collected])->all();
    }

    /**
     * @return list<array{id: string, name: string, count: int, value: int, collected: int, photo_version: int|null}>
     */
    private function practitioners(ReportFilters $filters, string $currency): array
    {
        $rows = $this->base($filters, $currency)->join('practitioners as p', 'p.id', '=', 'b.practitioner_id')
            ->selectRaw("p.id as id, p.name as name, p.photo_updated_at as photo,
                count(*) filter (where b.status in ('confirmed', 'completed', 'no_show')) as n,
                coalesce(sum(b.price_cents) filter (where b.status in ('confirmed', 'completed', 'no_show')), 0) as value,
                coalesce(sum(b.amount_collected_cents), 0) as collected")
            ->groupBy('p.id', 'p.name', 'p.photo_updated_at')->orderByDesc('value')->orderBy('p.name')->get();

        return $rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'count' => (int) $row->n,
            'value' => (int) $row->value,
            'collected' => (int) $row->collected,
            'photo_version' => $row->photo === null ? null : (int) strtotime((string) $row->photo),
        ])->all();
    }

    /**
     * @return list<array{id: string, name: string, count: int, value: int, collected: int}>|null null = un seul site (rien à comparer)
     */
    private function locations(ReportFilters $filters, string $currency): ?array
    {
        // Les sites sont déjà joints à la requête de base (alias « l »).
        $rows = $this->base($filters, $currency)
            ->selectRaw("l.id as id, l.name as name,
                count(*) filter (where b.status in ('confirmed', 'completed', 'no_show')) as n,
                coalesce(sum(b.price_cents) filter (where b.status in ('confirmed', 'completed', 'no_show')), 0) as value,
                coalesce(sum(b.amount_collected_cents), 0) as collected")
            ->groupBy('l.id', 'l.name')->orderByDesc('value')->orderBy('l.name')->get()
            ->map(fn (object $row): array => ['id' => (string) $row->id, 'name' => (string) $row->name, 'count' => (int) $row->n, 'value' => (int) $row->value, 'collected' => (int) $row->collected])->all();

        return count($rows) > 1 ? $rows : null;
    }

    /**
     * Encaissé par moyen de paiement.
     *
     * @return list<array{key: string, collected: int}>
     */
    private function methods(ReportFilters $filters, string $currency): array
    {
        return $this->base($filters, $currency)->where('b.amount_collected_cents', '>', 0)
            ->selectRaw("coalesce(b.payment_method, 'unspecified') as method, sum(b.amount_collected_cents) as collected")
            ->groupByRaw("coalesce(b.payment_method, 'unspecified')")->orderByDesc('collected')->get()
            ->map(fn (object $row): array => ['key' => (string) $row->method, 'collected' => (int) $row->collected])->all();
    }

    /**
     * Statut de paiement des rendez-vous terminés.
     *
     * @return list<array{key: string, count: int, price: int, collected: int}>
     */
    private function paymentStatuses(ReportFilters $filters, string $currency): array
    {
        return $this->base($filters, $currency)->where('b.status', 'completed')
            ->selectRaw('b.payment_status as status, count(*) as n, sum(b.price_cents) as price, sum(b.amount_collected_cents) as collected')
            ->groupBy('b.payment_status')->orderByDesc('n')->get()
            ->map(fn (object $row): array => ['key' => (string) $row->status, 'count' => (int) $row->n, 'price' => (int) $row->price, 'collected' => (int) $row->collected])->all();
    }
}
