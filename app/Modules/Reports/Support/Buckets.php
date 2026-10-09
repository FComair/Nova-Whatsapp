<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use Carbon\CarbonImmutable;

/**
 * Découpage d'une période en jours, semaines (lundi) ou mois selon sa durée, pour les courbes « dans le temps ».
 * Les intervalles vides existent quand même : une journée sans rendez-vous est un zéro, pas un trou.
 */
final class Buckets
{
    public const DAY = 'day';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public static function granularity(ReportFilters $filters): string
    {
        $days = $filters->days();

        return match (true) {
            $days <= 31 => self::DAY,
            $days <= 120 => self::WEEK,
            default => self::MONTH,
        };
    }

    /** Expression SQL du début d'intervalle (date locale) d'une colonne `timestamp` locale. */
    public static function sql(string $granularity, string $local): string
    {
        return match ($granularity) {
            self::DAY => "{$local}::date",
            self::WEEK => "date_trunc('week', {$local})::date",
            default => "date_trunc('month', {$local})::date",
        };
    }

    /**
     * Débuts d'intervalle (« AAAA-MM-JJ »), du premier au dernier jour de la période.
     *
     * @return list<string>
     */
    public static function keys(ReportFilters $filters, string $granularity): array
    {
        $keys = [];
        $cursor = match ($granularity) {
            self::WEEK => $filters->from->startOfWeek(),
            self::MONTH => $filters->from->startOfMonth(),
            default => $filters->from,
        };

        while ($cursor->lessThanOrEqualTo($filters->to)) {
            $keys[] = $cursor->toDateString();
            $cursor = match ($granularity) {
                self::WEEK => $cursor->addWeek(),
                self::MONTH => $cursor->addMonthNoOverflow(),
                default => $cursor->addDay(),
            };
        }

        return $keys;
    }

    /** Début d'intervalle de la date locale donnée. */
    public static function keyOf(CarbonImmutable $date, string $granularity): string
    {
        return match ($granularity) {
            self::WEEK => $date->startOfWeek()->toDateString(),
            self::MONTH => $date->startOfMonth()->toDateString(),
            default => $date->toDateString(),
        };
    }
}
