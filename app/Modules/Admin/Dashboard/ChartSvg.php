<?php

declare(strict_types=1);

namespace App\Modules\Admin\Dashboard;

/**
 * Géométrie des graphiques du tableau de bord (docs/33 §5). Les graphiques sont de simples SVG / HTML générés côté serveur : pas de
 * bibliothèque, lisibles au clavier et au lecteur d'écran (titres, résumé, tableau de données équivalent), et déterministes donc testables.
 */
final class ChartSvg
{
    public const WIDTH = 640;

    public const HEIGHT = 220;

    private const PAD_LEFT = 44;

    private const PAD_RIGHT = 12;

    private const PAD_TOP = 12;

    private const PAD_BOTTOM = 28;

    /**
     * Courbe(s) : une série ou deux (courante et précédente, alignées par rang).
     *
     * @param  array<string, int|float|null>  $current
     * @param  array<string, int|float|null>|null  $previous
     * @return array{width: int, height: int, max: float, ticks: list<array{y: float, value: float}>, current: array{line: string, area: string, points: list<array{x: float, y: float, key: string, value: float|int|null}>}, previous: string|null, labels: list<array{x: float, key: string}>, empty: bool}
     */
    public static function line(array $current, ?array $previous = null): array
    {
        $values = array_merge(array_values($current), array_values($previous ?? []));
        $max = self::niceMax($values === [] ? 0.0 : max(array_map(fn ($v): float => (float) ($v ?? 0), $values)));
        $n = count($current);
        $innerW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $innerH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $x = fn (int $i): float => self::PAD_LEFT + ($n <= 1 ? $innerW / 2 : $i * $innerW / ($n - 1));
        $y = fn (float $v): float => self::PAD_TOP + $innerH - ($max == 0 ? 0 : $v / $max * $innerH);

        $points = [];
        $i = 0;

        foreach ($current as $key => $value) {
            $points[] = ['x' => round($x($i), 1), 'y' => round($y((float) ($value ?? 0)), 1), 'key' => (string) $key, 'value' => $value];
            $i++;
        }

        $line = self::path($points);
        $baseline = round(self::PAD_TOP + $innerH, 1);
        $area = $points === [] ? '' : $line.' L'.end($points)['x'].' '.$baseline.' L'.$points[0]['x'].' '.$baseline.' Z';

        $prevPath = null;

        if ($previous !== null && $previous !== []) {
            $prevPoints = [];
            $j = 0;

            foreach (array_values($previous) as $value) {
                if ($j >= $n) {
                    break;
                }

                $prevPoints[] = ['x' => round($x($j), 1), 'y' => round($y((float) ($value ?? 0)), 1)];
                $j++;
            }

            $prevPath = self::path($prevPoints);
        }

        $ticks = [];

        foreach ([0, 0.25, 0.5, 0.75, 1] as $f) {
            $ticks[] = ['y' => round($y($max * $f), 1), 'value' => $max * $f];
        }

        $labelEvery = max(1, (int) ceil($n / 8));
        $labels = [];

        foreach ($points as $idx => $pt) {
            if ($idx % $labelEvery === 0) {
                $labels[] = ['x' => $pt['x'], 'key' => $pt['key']];
            }
        }

        return [
            'width' => self::WIDTH, 'height' => self::HEIGHT, 'max' => $max, 'ticks' => $ticks,
            'current' => ['line' => $line, 'area' => $area, 'points' => $points], 'previous' => $prevPath, 'labels' => $labels,
            'empty' => array_sum(array_map(fn ($v): float => (float) ($v ?? 0), $values)) == 0.0,
        ];
    }

    /**
     * Barres groupées : une barre par série et par tranche.
     *
     * @param  array<string, array<string, int|float>>  $series  nom de série => (clé de tranche => valeur)
     * @return array{width: int, height: int, max: float, ticks: list<array{y: float, value: float}>, bars: list<array{x: float, y: float, w: float, h: float, series: string, key: string, value: float|int}>, labels: list<array{x: float, key: string}>, empty: bool}
     */
    public static function bars(array $series): array
    {
        $keys = array_keys((array) reset($series));
        $count = max(1, count($keys));
        $names = array_keys($series);
        $max = self::niceMax((float) max(array_map(fn (array $s): float => (float) ($s === [] ? 0 : max($s)), $series) ?: [0]));
        $innerW = self::WIDTH - self::PAD_LEFT - self::PAD_RIGHT;
        $innerH = self::HEIGHT - self::PAD_TOP - self::PAD_BOTTOM;
        $slot = $innerW / $count;
        $barW = max(2.0, min(28.0, ($slot * 0.8) / max(1, count($names))));
        $bars = [];

        foreach ($keys as $i => $key) {
            foreach ($names as $s => $name) {
                $value = $series[$name][$key] ?? 0;
                $h = $max == 0 ? 0 : $value / $max * $innerH;
                $bars[] = [
                    'x' => round(self::PAD_LEFT + $i * $slot + ($slot - $barW * count($names)) / 2 + $s * $barW, 1),
                    'y' => round(self::PAD_TOP + $innerH - $h, 1), 'w' => round($barW - 1, 1), 'h' => round($h, 1),
                    'series' => (string) $name, 'key' => (string) $key, 'value' => $value,
                ];
            }
        }

        $ticks = [];

        foreach ([0, 0.5, 1] as $f) {
            $ticks[] = ['y' => round(self::PAD_TOP + $innerH - $innerH * $f, 1), 'value' => $max * $f];
        }

        $labelEvery = max(1, (int) ceil($count / 8));
        $labels = [];

        foreach ($keys as $i => $key) {
            if ($i % $labelEvery === 0) {
                $labels[] = ['x' => round(self::PAD_LEFT + $i * $slot + $slot / 2, 1), 'key' => (string) $key];
            }
        }

        return ['width' => self::WIDTH, 'height' => self::HEIGHT, 'max' => $max, 'ticks' => $ticks, 'bars' => $bars, 'labels' => $labels, 'empty' => array_sum(array_map('array_sum', $series)) == 0];
    }

    /**
     * Anneau : chaque segment avec sa part (%) et son arc (stroke-dasharray sur un cercle de rayon 15,9155, soit une circonférence de 100).
     *
     * @param  array<string, int>  $segments
     * @return list<array{key: string, value: int, percent: float, dash: string, offset: float}>
     */
    public static function donut(array $segments): array
    {
        $total = array_sum($segments);
        $out = [];
        $offset = 25.0;

        foreach ($segments as $key => $value) {
            if ($value <= 0 || $total <= 0) {
                continue;
            }

            $percent = $value * 100 / $total;
            $out[] = ['key' => (string) $key, 'value' => $value, 'percent' => round($percent, 1), 'dash' => round($percent, 2).' '.round(100 - $percent, 2), 'offset' => round($offset, 2)];
            $offset -= $percent;
        }

        return $out;
    }

    /** Arrondit un maximum vers une graduation lisible (1, 2, 2,5, 5, 10 × 10^n). */
    public static function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 1.0;
        }

        $pow = 10 ** floor(log10($max));
        $base = $max / $pow;
        $nice = match (true) {
            $base <= 1 => 1,
            $base <= 2 => 2,
            $base <= 2.5 => 2.5,
            $base <= 5 => 5,
            default => 10,
        };

        return (float) ($nice * $pow);
    }

    /**
     * @param  list<array{x: float, y: float}>  $points
     */
    private static function path(array $points): string
    {
        $d = '';

        foreach ($points as $i => $pt) {
            $d .= ($i === 0 ? 'M' : ' L').$pt['x'].' '.$pt['y'];
        }

        return $d;
    }
}
