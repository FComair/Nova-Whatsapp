<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Lang;

/**
 * Lignes détaillées d'un export : un rendez-vous par ligne, sans aucune donnée personnelle du client (ni nom, ni numéro, ni
 * notes). Le praticien et les montants n'y figurent que si l'utilisateur y a droit et les a demandés.
 *
 * Avec les montants, chaque ligne sépare le montant du service, ce qui en a été encaissé, le POURBOIRE, et le total payé (service
 * encaissé + pourboire), avec la devise, le statut de paiement et la date du paiement ; les bénéficiaires du pourboire et sa
 * répartition ne figurent que pour qui peut voir le détail par praticien.
 *
 * @phpstan-import-type Table from ReportTables
 */
final class DetailRows
{
    /** Garde-fou : un export ne dépasse jamais ce nombre de lignes (la période choisie est alors à réduire). */
    public const MAX_ROWS = 50000;

    public function __construct(private readonly ReportQuery $query) {}

    /**
     * @return Table
     */
    public function appointments(ReportFilters $filters, bool $practitioner, bool $money, string $currency = 'EUR'): array
    {
        $local = ReportQuery::LOCAL;
        $columns = [$this->col('reference', 'text'), $this->col('date', 'text'), $this->col('start', 'text'), $this->col('end', 'text'), $this->col('location', 'text')];

        if ($practitioner) {
            $columns[] = $this->col('practitioner', 'text');
        }

        array_push($columns, $this->col('service', 'text'), $this->col('status', 'text'), $this->col('source', 'text'), $this->col('duration_minutes', 'int'));

        if ($money) {
            array_push(
                $columns,
                $this->col('service_amount', 'money', $currency),
                $this->col('service_collected', 'money', $currency),
                $this->col('tip', 'money', $currency),
                $this->col('total_paid', 'money', $currency),
            );

            if ($practitioner) {
                array_push($columns, $this->col('tip_recipient', 'text'), $this->col('tip_allocation', 'text'));
            }

            array_push($columns, $this->col('currency', 'text'), $this->col('payment_status', 'text'), $this->col('payment_date', 'text'));
        }

        $rows = $this->bookings($filters, $money)
            ->join('services as s', 's.id', '=', 'b.service_id')->join('practitioners as p', 'p.id', '=', 'b.practitioner_id')
            ->leftJoin('booking_tips as t', 't.booking_id', '=', 'b.id')
            ->orderBy('b.starts_at')->limit(self::MAX_ROWS)
            ->selectRaw("b.id as reference, ({$local})::date as day, to_char({$local}, 'HH24:MI') as start_time, to_char(b.ends_at at time zone l.timezone, 'HH24:MI') as end_time,
                l.name as location, p.name as practitioner, s.name as service, b.status, b.source,
                round(extract(epoch from (b.ends_at - b.starts_at)) / 60)::int as minutes, b.price_cents, b.amount_collected_cents, b.payment_status, b.currency,
                coalesce(t.amount_cents, 0) as tip_cents,
                (select string_agg(rp.name, '; ' order by rp.name) from booking_tip_allocations a join practitioners rp on rp.id = a.practitioner_id where a.tip_id = t.id) as tip_recipients,
                (select string_agg(rp.name || ': ' || to_char(a.amount_cents / 100.0, 'FM999999990.00'), '; ' order by rp.name) from booking_tip_allocations a join practitioners rp on rp.id = a.practitioner_id where a.tip_id = t.id) as tip_allocation,
                (select max(e.created_at at time zone l.timezone) from booking_events e where e.booking_id = b.id and e.action = 'payment_updated') as paid_at")
            ->get();

        $data = $rows->map(function (object $row) use ($practitioner, $money): array {
            $line = [$row->reference, CarbonImmutable::parse((string) $row->day)->format('d/m/Y'), $row->start_time, $row->end_time, $row->location];

            if ($practitioner) {
                $line[] = $row->practitioner;
            }

            array_push($line, $row->service, $this->status((string) $row->status), (string) __('ui.reports.source.'.$row->source), (int) $row->minutes);

            if ($money) {
                array_push($line, $row->price_cents / 100, $row->amount_collected_cents / 100, $row->tip_cents / 100, ($row->amount_collected_cents + $row->tip_cents) / 100);

                if ($practitioner) {
                    array_push($line, (string) ($row->tip_recipients ?? ''), (string) ($row->tip_allocation ?? ''));
                }

                array_push($line, trim((string) $row->currency), (string) Lang::get('ui.planning.payment.'.$row->payment_status), $this->date($row->paid_at));
            }

            return $line;
        })->all();

        return ['key' => 'detail', 'title' => (string) __('ui.reports.table.detail'), 'section' => 'detail', 'columns' => $columns, 'rows' => $data];
    }

    /**
     * Pourboires : une ligne par bénéficiaire (un pourboire réparti entre deux praticiens fait deux lignes), avec le total du
     * pourboire, sa devise, le statut et la date du paiement.
     *
     * @return Table
     */
    public function tips(ReportFilters $filters, string $currency = 'EUR'): array
    {
        $local = ReportQuery::LOCAL;
        $columns = [
            $this->col('reference', 'text'), $this->col('date', 'text'), $this->col('service', 'text'), $this->col('tip_recipient', 'text'),
            $this->col('tip_share', 'money', $currency), $this->col('tip', 'money', $currency), $this->col('currency', 'text'), $this->col('payment_status', 'text'), $this->col('payment_date', 'text'),
        ];

        $rows = $this->bookings($filters, true)
            ->join('services as s', 's.id', '=', 'b.service_id')
            ->join('booking_tips as t', 't.booking_id', '=', 'b.id')
            ->join('booking_tip_allocations as a', 'a.tip_id', '=', 't.id')
            ->join('practitioners as rp', 'rp.id', '=', 'a.practitioner_id')
            ->orderBy('b.starts_at')->orderBy('rp.name')->limit(self::MAX_ROWS)
            ->selectRaw("b.id as reference, ({$local})::date as day, s.name as service, rp.name as recipient, a.amount_cents as share_cents, t.amount_cents as tip_cents, b.currency, b.payment_status,
                (select max(e.created_at at time zone l.timezone) from booking_events e where e.booking_id = b.id and e.action = 'payment_updated') as paid_at")
            ->get();

        $data = $rows->map(fn (object $row): array => [
            $row->reference,
            CarbonImmutable::parse((string) $row->day)->format('d/m/Y'),
            $row->service,
            $row->recipient,
            $row->share_cents / 100,
            $row->tip_cents / 100,
            trim((string) $row->currency),
            (string) Lang::get('ui.planning.payment.'.$row->payment_status),
            $this->date($row->paid_at),
        ])->all();

        return ['key' => 'tips_detail', 'title' => (string) __('ui.reports.table.tips_detail'), 'section' => 'detail', 'columns' => $columns, 'rows' => $data];
    }

    /** Rendez-vous de la période ; le filtre de statut de paiement ne s'applique qu'avec les montants (rapport financier). */
    private function bookings(ReportFilters $filters, bool $money): Builder
    {
        return $this->query->bookings($filters)
            ->when($money && $filters->paymentStatus !== null, fn (Builder $query) => $query->where('b.payment_status', $filters->paymentStatus));
    }

    private function date(mixed $value): string
    {
        return $value === null ? '' : CarbonImmutable::parse((string) $value)->format('d/m/Y');
    }

    private function status(string $status): string
    {
        return (string) __('ui.planning.status.'.$status);
    }

    /**
     * @return array{label: string, type: string}
     */
    private function col(string $key, string $type, ?string $currency = null): array
    {
        return ['label' => (string) __('ui.reports.col.'.$key).($currency === null ? '' : " ({$currency})"), 'type' => $type];
    }
}
