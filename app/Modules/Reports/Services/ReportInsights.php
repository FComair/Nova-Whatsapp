<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

/**
 * Constats en une phrase, affichés seulement quand les chiffres de la page les soutiennent (échantillon suffisant, écart net).
 * Le ton reste neutre : une hausse ou une baisse n'est jamais présentée comme bonne ou mauvaise. Chaque constat est une clé de
 * traduction et ses paramètres, jamais du texte fixe.
 */
final class ReportInsights
{
    /** Nombre minimal de rendez-vous pour parler d'un jour ou d'un service « le plus demandé ». */
    private const MIN_SAMPLE = 20;

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{key: string, params: array<string, int|float|string>}>
     */
    public function appointments(array $report): array
    {
        $insights = [];
        $metrics = $report['metrics'];
        $heat = $report['heatmap'];

        if ($heat['total'] >= self::MIN_SAMPLE) {
            $perDay = array_map('array_sum', $heat['cells']);
            arsort($perDay);
            $day = (int) array_key_first($perDay);
            $insights[] = ['key' => 'busiest_weekday', 'params' => ['day' => $day, 'share' => (int) round($perDay[$day] / $heat['total'] * 100)]];

            $perHour = [];

            foreach ($heat['cells'] as $hours) {
                foreach ($hours as $hour => $count) {
                    $perHour[$hour] = ($perHour[$hour] ?? 0) + $count;
                }
            }

            arsort($perHour);
            $hour = (int) array_key_first($perHour);
            $insights[] = ['key' => 'busiest_hour', 'params' => ['hour' => sprintf('%02d:00', $hour), 'share' => (int) round($perHour[$hour] / $heat['total'] * 100)]];
        }

        $top = $report['services'][0] ?? null;
        $total = $metrics['total']['value'];

        if ($top !== null && $top['id'] !== null && $total >= self::MIN_SAMPLE && $top['count'] / $total >= 0.3) {
            $insights[] = ['key' => 'top_service', 'params' => ['service' => $top['name'], 'share' => (int) round($top['count'] / $total * 100)]];
        }

        $noShow = $metrics['no_show_rate'];

        if ($noShow['delta'] !== null && abs($noShow['delta']) >= 3) {
            $insights[] = ['key' => $noShow['delta'] > 0 ? 'no_show_rate_up' : 'no_show_rate_down', 'params' => ['points' => abs($noShow['delta'])]];
        }

        $volume = $metrics['total'];

        if ($volume['pct'] !== null && abs($volume['pct']) >= 10) {
            $insights[] = ['key' => $volume['pct'] > 0 ? 'volume_up' : 'volume_down', 'params' => ['pct' => abs($volume['pct'])]];
        }

        return $insights;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{key: string, params: array<string, int|float|string>}>
     */
    public function customers(array $report): array
    {
        $insights = [];
        $active = $report['metrics']['active']['value'];
        $returning = $report['metrics']['returning']['value'];

        if ($active >= self::MIN_SAMPLE) {
            $insights[] = ['key' => 'returning_share', 'params' => ['share' => (int) round($returning / $active * 100)]];
        }

        $inactive = $report['metrics']['inactive']['value'];

        if ($inactive > 0) {
            $insights[] = ['key' => 'inactive_customers', 'params' => ['count' => $inactive, 'days' => $report['inactive_days']]];
        }

        return $insights;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{key: string, params: array<string, int|float|string>}>
     */
    public function financial(array $report): array
    {
        $insights = [];
        $outstanding = $report['metrics']['outstanding']['value'];

        if ($outstanding > 0) {
            $insights[] = ['key' => 'outstanding_amount', 'params' => ['amount' => $outstanding]];
        }

        $collected = $report['metrics']['collected'];

        if ($collected['pct'] !== null && abs($collected['pct']) >= 10) {
            $insights[] = ['key' => $collected['pct'] > 0 ? 'collected_up' : 'collected_down', 'params' => ['pct' => abs($collected['pct'])]];
        }

        return $insights;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array{key: string, params: array<string, int|float|string>}>
     */
    public function practitioners(array $report): array
    {
        $rows = array_values(array_filter($report['rows'], fn (array $row): bool => $row['scheduled_minutes'] >= 600 && $row['utilization'] !== null));

        if (count($rows) < 2) {
            return [];
        }

        usort($rows, fn (array $a, array $b): int => $b['utilization'] <=> $a['utilization']);
        $high = $rows[0];
        $low = $rows[count($rows) - 1];

        if (($high['utilization'] - $low['utilization']) * 100 < 20) {
            return [];
        }

        return [['key' => 'utilization_spread', 'params' => ['high' => $high['name'], 'high_rate' => (int) round($high['utilization'] * 100), 'low' => $low['name'], 'low_rate' => (int) round($low['utilization'] * 100)]]];
    }
}
