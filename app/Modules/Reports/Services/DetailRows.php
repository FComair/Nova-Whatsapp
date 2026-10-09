<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;

/**
 * Lignes détaillées d'un export : un rendez-vous par ligne, sans aucune donnée personnelle du client (ni nom, ni numéro, ni
 * notes). Le praticien et les montants n'y figurent que si l'utilisateur y a droit et les a demandés.
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
        $columns = [$this->col('date', 'text'), $this->col('start', 'text'), $this->col('end', 'text'), $this->col('location', 'text')];

        if ($practitioner) {
            $columns[] = $this->col('practitioner', 'text');
        }

        array_push($columns, $this->col('service', 'text'), $this->col('status', 'text'), $this->col('source', 'text'), $this->col('duration_minutes', 'int'));

        if ($money) {
            array_push($columns, $this->col('price', 'money', $currency), $this->col('collected', 'money', $currency), $this->col('payment_status', 'text'));
        }

        $rows = $this->query->bookings($filters)
            ->join('services as s', 's.id', '=', 'b.service_id')->join('practitioners as p', 'p.id', '=', 'b.practitioner_id')
            ->orderBy('b.starts_at')->limit(self::MAX_ROWS)
            ->selectRaw("({$local})::date as day, to_char({$local}, 'HH24:MI') as start_time, to_char(b.ends_at at time zone l.timezone, 'HH24:MI') as end_time,
                l.name as location, p.name as practitioner, s.name as service, b.status, b.source,
                round(extract(epoch from (b.ends_at - b.starts_at)) / 60)::int as minutes, b.price_cents, b.amount_collected_cents, b.payment_status, b.currency")
            ->get();

        $data = $rows->map(function (object $row) use ($practitioner, $money): array {
            $line = [CarbonImmutable::parse((string) $row->day)->format('d/m/Y'), $row->start_time, $row->end_time, $row->location];

            if ($practitioner) {
                $line[] = $row->practitioner;
            }

            array_push($line, $row->service, $this->status((string) $row->status), (string) __('ui.reports.source.'.$row->source), (int) $row->minutes);

            if ($money) {
                array_push($line, $row->price_cents / 100, $row->amount_collected_cents / 100, (string) Lang::get('ui.planning.payment.'.$row->payment_status));
            }

            return $line;
        })->all();

        return ['key' => 'detail', 'title' => (string) __('ui.reports.table.detail'), 'section' => 'detail', 'columns' => $columns, 'rows' => $data];
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
