<?php

declare(strict_types=1);

namespace App\Modules\Admin\Dashboard;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Période d'analyse du tableau de bord (docs/33 §2) et période précédente équivalente.
 *
 * Les périodes « calendaires » (cette semaine, ce mois, ce trimestre, cette année) sont arrêtées à aujourd'hui ; la période précédente est la
 * même portion de la période calendaire précédente (ex. : du 1er au 10 de ce mois contre du 1er au 10 du mois dernier). « Aujourd'hui » se
 * compare à hier ; « le mois dernier » au mois d'avant ; une période libre à la période de même durée qui la précède immédiatement.
 */
final class DashboardPeriod
{
    public const PRESETS = ['today', 'this_week', 'this_month', 'last_month', 'this_quarter', 'this_year', 'custom'];

    /** Durée maximale d'une période libre (jours) : au-delà, les graphiques deviendraient illisibles et les requêtes coûteuses. */
    public const MAX_DAYS = 800;

    private function __construct(
        public readonly string $preset,
        public readonly Carbon $from,
        public readonly Carbon $until,
        public readonly Carbon $previousFrom,
        public readonly Carbon $previousUntil,
    ) {}

    public static function make(string $preset = 'this_month', ?string $from = null, ?string $until = null, ?Carbon $now = null): self
    {
        $now = ($now ?? now())->copy();
        $today = $now->copy()->startOfDay();

        switch ($preset) {
            case 'today':
                return self::build($preset, $today, $today->copy()->endOfDay(), $today->copy()->subDay(), $today->copy()->subDay()->endOfDay());
            case 'this_week':
                $start = $today->copy()->startOfWeek();
                $span = (int) $start->diffInDays($today);

                return self::build($preset, $start, $today->copy()->endOfDay(), $start->copy()->subWeek(), $start->copy()->subWeek()->addDays($span)->endOfDay());
            case 'this_month':
                $start = $today->copy()->startOfMonth();
                $prev = $start->copy()->subMonthNoOverflow();

                return self::build($preset, $start, $today->copy()->endOfDay(), $prev, self::sameDayOf($prev, $today));
            case 'last_month':
                $start = $today->copy()->startOfMonth()->subMonthNoOverflow();
                $prev = $start->copy()->subMonthNoOverflow();

                return self::build($preset, $start, $start->copy()->endOfMonth(), $prev, $prev->copy()->endOfMonth());
            case 'this_quarter':
                $start = $today->copy()->startOfQuarter();
                $prev = $start->copy()->subMonthsNoOverflow(3);
                $elapsed = (int) $start->diffInDays($today);

                return self::build($preset, $start, $today->copy()->endOfDay(), $prev, $prev->copy()->addDays($elapsed)->endOfDay());
            case 'this_year':
                $start = $today->copy()->startOfYear();
                $prev = $start->copy()->subYear();
                $elapsed = (int) $start->diffInDays($today);

                return self::build($preset, $start, $today->copy()->endOfDay(), $prev, $prev->copy()->addDays($elapsed)->endOfDay());
            case 'custom':
                $start = self::parse($from) ?? $today->copy()->subDays(29);
                $end = self::parse($until) ?? $today;

                if ($end->lt($start)) {
                    [$start, $end] = [$end, $start];
                }

                $start = $start->copy()->startOfDay();
                $end = $end->copy()->endOfDay();
                $days = (int) $start->diffInDays($end->copy()->startOfDay()) + 1;

                if ($days > self::MAX_DAYS) {
                    $start = $end->copy()->startOfDay()->subDays(self::MAX_DAYS - 1);
                    $days = self::MAX_DAYS;
                }

                return self::build($preset, $start, $end, $start->copy()->subDays($days), $start->copy()->subDay()->endOfDay());
        }

        throw new InvalidArgumentException("Période inconnue : {$preset}");
    }

    /** Jours couverts (au moins 1). */
    public function days(): int
    {
        return (int) $this->from->copy()->startOfDay()->diffInDays($this->until->copy()->startOfDay()) + 1;
    }

    /** Les graphiques au jour jusqu'à 62 jours, au mois au-delà. */
    public function bucket(): string
    {
        return $this->days() <= 62 ? 'day' : 'month';
    }

    /**
     * Début de chaque tranche de la période (jour ou mois), pour tracer une série sans trou.
     *
     * @return list<Carbon>
     */
    public function buckets(bool $previous = false): array
    {
        $from = $previous ? $this->previousFrom : $this->from;
        $until = $previous ? $this->previousUntil : $this->until;
        $step = $this->bucket();
        $cursor = $step === 'day' ? $from->copy()->startOfDay() : $from->copy()->startOfMonth();
        $out = [];

        while ($cursor->lte($until)) {
            $out[] = $cursor->copy();
            $step === 'day' ? $cursor->addDay() : $cursor->addMonthNoOverflow();
        }

        return $out;
    }

    public function format(Carbon $date): string
    {
        return $date->format('d/m/Y');
    }

    private static function build(string $preset, Carbon $from, Carbon $until, Carbon $previousFrom, Carbon $previousUntil): self
    {
        return new self($preset, $from, $until, $previousFrom, $previousUntil);
    }

    /** Même jour du mois que `$today` dans le mois de `$monthStart`, sans dépasser sa fin. */
    private static function sameDayOf(Carbon $monthStart, Carbon $today): Carbon
    {
        $day = min($today->day, $monthStart->daysInMonth);

        return $monthStart->copy()->day($day)->endOfDay();
    }

    private static function parse(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
