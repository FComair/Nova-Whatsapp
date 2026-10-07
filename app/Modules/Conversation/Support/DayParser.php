<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Support;

use Carbon\CarbonImmutable;

/**
 * Comprend le jour tapé par le client : « aujourd'hui », « demain », « après-demain », un jour de la semaine
 * (« lundi », « lundi prochain »), ou une date (« 12/09 », « 12/09/2026 »).
 *
 * Un jour de la semaine désigne toujours sa PROCHAINE occurrence, jamais aujourd'hui (règle du flux n8n) ;
 * « prochain » ajoute une semaine.
 */
final class DayParser
{
    /**
     * @param  CarbonImmutable  $today  aujourd'hui, à minuit, dans le fuseau du site
     */
    public static function parse(string $raw, CarbonImmutable $today): ?CarbonImmutable
    {
        $input = Text::normalize($raw);

        if ($input === '') {
            return null;
        }

        if (Keywords::equals($input, 'today')) {
            return $today;
        }

        if (Keywords::equals($input, 'tomorrow')) {
            return $today->addDay();
        }

        if (Keywords::equals($input, 'after_tomorrow')) {
            return $today->addDays(2);
        }

        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2}|\d{4}))?$/', $input, $match) === 1) {
            return self::date((int) $match[1], (int) $match[2], isset($match[3]) ? (int) $match[3] : null, $today);
        }

        return self::weekday($input, $today);
    }

    private static function date(int $day, int $month, ?int $year, CarbonImmutable $today): ?CarbonImmutable
    {
        if ($year !== null && $year < 100) {
            $year += 2000;
        }

        $candidateYear = $year ?? $today->year;

        if (! checkdate($month, $day, $candidateYear)) {
            return null;
        }

        $date = $today->setDate($candidateYear, $month, $day)->startOfDay();

        // Sans année : la date est la prochaine à venir (« 12/01 » tapé en décembre = janvier prochain).
        if ($year === null && $date < $today) {
            $date = $date->addYear();
        }

        return $date;
    }

    private static function weekday(string $input, CarbonImmutable $today): ?CarbonImmutable
    {
        $next = Keywords::list('next');
        $words = array_values(array_filter(explode(' ', $input), static fn (string $word): bool => $word !== ''));
        $forceNextWeek = count(array_intersect($words, $next)) > 0;
        $rest = implode(' ', array_values(array_diff($words, $next)));
        $weekdays = Keywords::weekdays();

        if (! isset($weekdays[$rest])) {
            return null;
        }

        $difference = $weekdays[$rest] - $today->dayOfWeek;

        if ($difference <= 0) {
            $difference += 7;
        }

        if ($forceNextWeek) {
            $difference += 7;
        }

        return $today->addDays($difference);
    }
}
