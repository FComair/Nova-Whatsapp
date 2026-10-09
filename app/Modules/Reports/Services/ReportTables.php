<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Reports\Support\Buckets;
use App\Modules\Reports\Support\ReportAccess;
use App\Modules\Reports\Support\ReportFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;

/**
 * Un rapport vu comme des tableaux (export CSV / Excel) : mêmes chiffres que l'écran, avec leurs unités dans les en-têtes.
 * Types de colonne : text, int, number, pct (0 à 100), money (unités entières de la devise), hours (décimales).
 * Aucune donnée personnelle de client : jamais de nom, de numéro ni d'adresse.
 *
 * @phpstan-type Column array{label: string, type: string}
 * @phpstan-type Table array{key: string, title: string, section: string, columns: list<Column>, rows: list<list<int|float|string|null>>, sheet?: string}
 */
final class ReportTables
{
    /** Devise des montants du rapport en cours de conversion. */
    private string $currency = 'EUR';

    public function __construct(private readonly ReportAccess $access) {}

    /**
     * @param  array<string, mixed>  $report
     * @return list<Table>
     */
    public function for(string $category, array $report, ReportFilters $filters, bool $staff, string $currency = 'EUR'): array
    {
        $this->currency = $currency;

        return match ($category) {
            ReportAccess::APPOINTMENTS => $this->appointments($report, $filters),
            ReportAccess::CUSTOMERS => $this->customers($report, $filters),
            ReportAccess::FINANCIAL => $this->financial($report, $filters, $staff),
            default => $this->practitioners($report, $staff),
        };
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<Table>
     */
    private function appointments(array $report, ReportFilters $filters): array
    {
        $tables = [$this->summary($report['metrics'], ['total', 'completed', 'upcoming', 'awaiting', 'cancelled', 'no_show', 'rescheduled', 'cancellation_rate', 'no_show_rate', 'completion_rate', 'avg_per_day'], 'appointments')];

        $tables[] = $this->table('over_time', 'breakdown', [$this->text('period'), $this->int('status.completed'), $this->int('status.upcoming'), $this->int('status.awaiting'), $this->int('status.no_show'), $this->int('status.cancelled'), $this->int('metric.total')],
            array_map(fn (array $b): array => [$this->bucketLabel($b['key'], $report['series']['granularity']), $b['completed'], $b['upcoming'], $b['awaiting'], $b['no_show'], $b['cancelled'], $b['total']], $report['series']['buckets']));

        $total = max(1, array_sum(array_column($report['statuses'], 'count')));
        $tables[] = $this->table('by_status', 'breakdown', [$this->text('status'), $this->int('appointments'), $this->pct('share')],
            array_map(fn (array $s): array => [$this->t("status.{$s['key']}"), $s['count'], round($s['count'] / $total * 100, 1)], $report['statuses']));

        $tables[] = $this->heatmap($report['heatmap']);

        $tables[] = $this->table('by_source', 'breakdown', [$this->text('source'), $this->int('appointments'), $this->pct('share')],
            array_map(fn (array $s): array => [$this->t("source.{$s['key']}"), $s['count'], $s['share'] === null ? null : round($s['share'] * 100, 1)], $report['sources']));

        $money = $this->access->canSeeFinancial();
        $columns = [$this->text('service'), $this->int('appointments'), $this->int('status.cancelled')];

        if ($money) {
            $columns[] = $this->money('value', $this->currency);
        }

        $tables[] = $this->table('by_service', 'breakdown', $columns, array_map(function (array $s) use ($money): array {
            $row = [$s['name'] ?? $this->t('others'), $s['count'], $s['cancelled']];

            if ($money) {
                $row[] = $s['value_cents'] === null ? null : $s['value_cents'] / 100;
            }

            return $row;
        }, $report['services']));

        return $tables;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<Table>
     */
    private function customers(array $report, ReportFilters $filters): array
    {
        $tables = [$this->summary($report['metrics'], ['customers_total', 'new', 'active', 'returning', 'with_upcoming', 'inactive', 'avg_per_customer', 'returning_rate', 'retention'], 'customers')];

        $tables[] = $this->table('over_time', 'breakdown', [$this->text('period'), $this->int('metric.new'), $this->int('metric.active')],
            array_map(fn (array $b): array => [$this->bucketLabel($b['key'], $report['series']['granularity']), $b['new'], $b['active']], $report['series']['buckets']));

        $tables[] = $this->table('by_preferred_service', 'breakdown', [$this->text('service'), $this->int('customers')], array_map(fn (array $s): array => [$s['name'], $s['count']], $report['by_service']));
        $tables[] = $this->table('by_first_source', 'breakdown', [$this->text('source'), $this->int('customers')], array_map(fn (array $s): array => [$this->t("source.{$s['key']}"), $s['count']], $report['by_source']));

        if ($report['by_location'] !== null) {
            $tables[] = $this->table('by_main_location', 'breakdown', [$this->text('location'), $this->int('customers')], array_map(fn (array $s): array => [$s['name'], $s['count']], $report['by_location']));
        }

        return $tables;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<Table>
     */
    private function financial(array $report, ReportFilters $filters, bool $staff): array
    {
        $currency = $report['currency'];
        $tables = [$this->summary($report['metrics'], ['booked_value', 'completed_value', 'collected', 'outstanding', 'waived', 'no_show_value', 'cancelled_value', 'avg_value', 'collection_rate', 'tips', 'tips_count', 'tips_avg', 'total_paid'], 'financial', $currency)];

        $tables[] = $this->table('over_time', 'breakdown', [$this->text('period'), $this->money('metric.booked_value', $currency), $this->money('metric.collected', $currency), $this->money('metric.tips', $currency)],
            array_map(fn (array $b): array => [$this->bucketLabel($b['key'], $report['series']['granularity']), $b['value'] / 100, $b['collected'] / 100, ($b['tips'] ?? 0) / 100], $report['series']['buckets']));

        // Les pourboires ont leur colonne, à part : jamais fondus dans la valeur ni dans l'encaissé du service.
        $split = function (string $title, string $label, array $rows) use ($currency): array {
            $withTips = $rows !== [] && array_key_exists('tips', $rows[0]);
            $columns = [$this->text($label), $this->int('appointments'), $this->money('metric.booked_value', $currency), $this->money('metric.collected', $currency)];

            if ($withTips) {
                $columns[] = $this->money('metric.tips', $currency);
            }

            return $this->table($title, 'breakdown', $columns, array_map(fn (array $r): array => [$r['name'], $r['count'], $r['value'] / 100, $r['collected'] / 100, ...($withTips ? [$r['tips'] / 100] : [])], $rows));
        };

        $tables[] = $split('by_service', 'service', $report['services']);

        if ($staff && $report['practitioners'] !== null) {
            $tables[] = $split('by_practitioner', 'practitioner', $report['practitioners']);
        }

        if ($report['locations'] !== null) {
            $tables[] = $split('by_location', 'location', $report['locations']);
        }

        if ($staff && $report['tips_by_practitioner'] !== null) {
            $tables[] = $this->table('tips_by_practitioner', 'breakdown', [$this->text('practitioner'), $this->money('metric.tips', $currency), $this->int('tip_count'), $this->pct('share')],
                array_map(fn (array $r): array => [$r['name'], $r['tips'] / 100, $r['count'], $r['share'] === null ? null : round($r['share'] * 100, 1)], $report['tips_by_practitioner']));
        }

        $tables[] = $this->table('by_method', 'breakdown', [$this->text('method'), $this->money('metric.collected', $currency)],
            array_map(fn (array $m): array => [$this->method($m['key']), $m['collected'] / 100], $report['methods']));

        $tables[] = $this->table('payment_status', 'breakdown', [$this->text('payment_status'), $this->int('appointments'), $this->money('price', $currency), $this->money('metric.collected', $currency)],
            array_map(fn (array $p): array => [Lang::get("ui.planning.payment.{$p['key']}"), $p['count'], $p['price'] / 100, $p['collected'] / 100], $report['payment_statuses']));

        return $tables;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<Table>
     */
    private function practitioners(array $report, bool $staff): array
    {
        $tables = [$this->summary($report['metrics'], ['assigned', 'completed', 'scheduled', 'booked', 'available', 'utilization', 'cancellation_rate', 'no_show_rate'], 'practitioners')];

        if ($staff) {
            $money = $report['rows'] !== [] && $report['rows'][0]['value_cents'] !== null;
            $columns = [$this->text('practitioner'), $this->int('assigned'), $this->int('status.completed'), $this->int('status.cancelled'), $this->int('status.no_show'), $this->hours('scheduled'), $this->hours('booked'), $this->hours('available'), $this->pct('utilization'), $this->int('working_days'), $this->int('days_off'), $this->int('non_working_days')];

            if ($money) {
                $columns[] = $this->money('metric.booked_value', $this->currencyLabel());
                $columns[] = $this->money('metric.collected', $this->currencyLabel());
                $columns[] = $this->money('metric.tips', $this->currencyLabel());
            }

            $tables[] = $this->table('comparison', 'breakdown', $columns, array_map(function (array $r) use ($money): array {
                $row = [$r['name'], $r['assigned'], $r['completed'], $r['cancelled'], $r['no_show'], round($r['scheduled_minutes'] / 60, 2), round($r['booked_minutes'] / 60, 2), round($r['available_minutes'] / 60, 2), $r['utilization'] === null ? null : round($r['utilization'] * 100, 1), $r['working_days'], $r['days_off'], $r['non_working_days']];

                if ($money) {
                    $row[] = ($r['value_cents'] ?? 0) / 100;
                    $row[] = ($r['collected_cents'] ?? 0) / 100;
                    $row[] = ($r['tips_cents'] ?? 0) / 100;
                }

                return $row;
            }, $report['rows']));

            $mix = [];

            foreach ($report['rows'] as $row) {
                foreach ($row['services'] as $service) {
                    $mix[] = [$row['name'], $service['name'], $service['count']];
                }
            }

            $tables[] = $this->table('service_mix', 'breakdown', [$this->text('practitioner'), $this->text('service'), $this->int('appointments')], $mix);
            $tables[] = $this->table('time_off', 'breakdown', [$this->text('practitioner'), $this->text('from'), $this->text('to'), $this->int('days')],
                array_map(fn (array $o): array => [$o['practitioner'], $this->date($o['from']), $this->date($o['to']), $o['days']], $report['time_off']));
        }

        $tables[] = $this->heatmap($report['heatmap']);

        return $tables;
    }

    /**
     * @param  array<string, array<string, mixed>>  $metrics
     * @param  list<string>  $keys
     * @return Table
     */
    private function summary(array $metrics, array $keys, string $scope, ?string $currency = null): array
    {
        $rows = [];

        foreach ($keys as $key) {
            $m = $metrics[$key];
            [$unit, $factor] = $this->unit($m['kind'], $key, $currency);
            $scale = fn ($value) => $value === null ? null : round($value * $factor, $m['kind'] === 'rate' ? 1 : 2);
            $rows[] = [
                $this->t("metric.{$key}"),
                $unit,
                $scale($m['value']),
                $scale($m['previous']),
                $m['kind'] === 'rate' ? $m['delta'] : $scale($m['delta']),
                $m['pct'],
            ];
        }

        return $this->table('summary', 'summary', [$this->text('metric'), $this->text('unit'), $this->number('current'), $this->number('previous'), $this->number('change'), $this->number('change_pct')], $rows);
    }

    /**
     * @return array{0: string, 1: float}
     */
    private function unit(string $kind, string $key, ?string $currency): array
    {
        return match (true) {
            $kind === 'rate' => [$this->t('unit.percent'), 100.0],
            $kind === 'money' => [$currency ?? $this->currencyLabel(), 0.01],
            $kind === 'minutes' => [$this->t('unit.hours'), 1 / 60],
            $key === 'avg_per_day' => [$this->t('unit.per_day'), 1.0],
            $key === 'avg_per_customer' => [$this->t('unit.per_customer'), 1.0],
            default => [$this->t('unit.count'), 1.0],
        };
    }

    /**
     * @param  array{cells: array<int, array<int, int>>, first_hour: int, last_hour: int}  $heatmap
     * @return Table
     */
    private function heatmap(array $heatmap): array
    {
        $hours = range($heatmap['first_hour'], $heatmap['last_hour']);

        return $this->table('heatmap', 'breakdown', [$this->text('weekday'), ...array_map(fn (int $h): array => ['label' => sprintf('%02d:00', $h), 'type' => 'int'], $hours)],
            array_map(fn (int $day): array => [$this->t("weekday.{$day}"), ...array_map(fn (int $h): int => $heatmap['cells'][$day][$h], $hours)], range(1, 7)));
    }

    /**
     * @param  list<Column>  $columns
     * @param  list<list<int|float|string|null>>  $rows
     * @return Table
     */
    private function table(string $key, string $section, array $columns, array $rows): array
    {
        return ['key' => $key, 'title' => $this->t("table.{$key}"), 'section' => $section, 'columns' => $columns, 'rows' => $rows];
    }

    private function t(string $key): string
    {
        return (string) __("ui.reports.{$key}");
    }

    private function method(string $key): string
    {
        $path = "ui.planning.payment.{$key}";

        return Lang::has($path) ? (string) __($path) : $this->t('method_unspecified');
    }

    private function date(string $iso): string
    {
        return CarbonImmutable::parse($iso)->format('d/m/Y');
    }

    private function bucketLabel(string $key, string $granularity): string
    {
        $date = CarbonImmutable::parse($key);

        return $granularity === Buckets::MONTH ? $date->format('m/Y') : $date->format('d/m/Y');
    }

    private function currencyLabel(): string
    {
        return $this->currency;
    }

    /** @return Column */
    private function text(string $key): array
    {
        return ['label' => $this->t("col.{$key}"), 'type' => 'text'];
    }

    /** @return Column */
    private function int(string $key): array
    {
        return ['label' => $this->t(str_contains($key, '.') ? $key : "col.{$key}"), 'type' => 'int'];
    }

    /** @return Column */
    private function number(string $key): array
    {
        return ['label' => $this->t("col.{$key}"), 'type' => 'number'];
    }

    /** @return Column */
    private function pct(string $key): array
    {
        return ['label' => $this->t("col.{$key}").' (%)', 'type' => 'pct'];
    }

    /** @return Column */
    private function hours(string $key): array
    {
        return ['label' => $this->t("col.{$key}").' ('.$this->t('unit.hours').')', 'type' => 'hours'];
    }

    /** @return Column */
    private function money(string $key, string $currency): array
    {
        return ['label' => $this->t(str_contains($key, '.') ? $key : "col.{$key}").' ('.$currency.')', 'type' => 'money'];
    }
}
