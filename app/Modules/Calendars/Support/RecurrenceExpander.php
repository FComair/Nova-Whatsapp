<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Support;

use App\Modules\Booking\Support\Intervals;
use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Transforme des événements iCalendar en plages OCCUPÉES sur une fenêtre de temps, récurrences comprises.
 *
 * Pris en charge : FREQ = DAILY, WEEKLY, MONTHLY, YEARLY ; INTERVAL, COUNT, UNTIL, WKST, BYDAY (avec rang : 1MO, -1FR),
 * BYMONTHDAY (négatif accepté), BYMONTH ; EXDATE ; occurrences modifiées (RECURRENCE-ID) ; heure d'été/hiver (le calcul se fait
 * en heure locale de l'événement) ; événements annulés ou « libres » (TRANSP:TRANSPARENT) ignorés.
 *
 * Règles non prises en charge (BYSETPOS, BYWEEKNO, BYYEARDAY, BYHOUR…, fréquences sous le jour) : seule la première occurrence
 * est comptée. Le compteur `unsupported` permet de le signaler.
 */
final class RecurrenceExpander
{
    private const MAX_PERIODS = 20000;

    private const WEEKDAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    private const UNSUPPORTED_KEYS = ['BYSETPOS', 'BYWEEKNO', 'BYYEARDAY', 'BYHOUR', 'BYMINUTE', 'BYSECOND'];

    /** Nombre de règles de récurrence non prises en charge rencontrées lors du dernier calcul. */
    public int $unsupported = 0;

    /**
     * @param  list<IcsEvent>  $events
     * @param  int  $from  secondes Unix (incluse)
     * @param  int  $to  secondes Unix (exclue)
     * @return list<array{0: int, 1: int}> plages occupées, fusionnées, coupées à la fenêtre
     */
    public function busy(array $events, int $from, int $to): array
    {
        $this->unsupported = 0;
        $overridden = [];

        foreach ($events as $event) {
            if ($event->recurrenceId !== null) {
                $overridden[$event->uid][] = $event->recurrenceId;
            }
        }

        $busy = [];

        foreach ($events as $event) {
            if ($event->cancelled || $event->transparent) {
                continue;
            }

            foreach ($this->occurrences($event, $from, $to, $overridden[$event->uid] ?? []) as [$start, $end]) {
                if ($end > $start) {
                    $busy[] = [max($start, $from), min($end, $to)];
                }
            }
        }

        return Intervals::normalize($busy);
    }

    /**
     * @param  list<int>  $overriddenStarts  débuts d'occurrences remplacées par une occurrence modifiée
     * @return list<array{0: int, 1: int}>
     */
    private function occurrences(IcsEvent $event, int $from, int $to, array $overriddenStarts): array
    {
        $single = Intervals::overlaps($event->start, $event->end, $from, $to) ? [[$event->start, $event->end]] : [];

        if ($event->rrule === null || $event->recurrenceId !== null) {
            return $single;
        }

        $rule = $event->rrule;

        if (! in_array($rule['FREQ'] ?? '', ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true) || array_intersect(self::UNSUPPORTED_KEYS, array_keys($rule)) !== []) {
            $this->unsupported++;

            return $single;
        }

        $tz = $event->timezone;
        $first = CarbonImmutable::createFromTimestamp($event->start, $tz);
        $spanDays = (int) round(($event->end - $event->start) / 86400);
        $limit = $this->until($rule['UNTIL'] ?? null, $tz);
        $count = isset($rule['COUNT']) ? (int) $rule['COUNT'] : null;
        $excluded = array_flip([...$event->exdates, ...$overriddenStarts]);
        $result = [];
        $emitted = 0;

        foreach ($this->withStart($this->days($rule, $first), $first->startOfDay()) as $day) {
            $start = $day->setTime((int) $first->format('G'), (int) $first->format('i'), (int) $first->format('s'));
            $startTs = $start->getTimestamp();

            if ($limit !== null && $startTs > $limit) {
                break;
            }

            if ($startTs >= $to) {
                break;
            }

            $emitted++;

            if ($count !== null && $emitted > $count) {
                break;
            }

            $endTs = $event->allDay ? $start->addDays(max(1, $spanDays))->getTimestamp() : $startTs + ($event->end - $event->start);

            if (isset($excluded[$startTs]) || in_array($start->format('Y-m-d'), $event->exdateDays, true) || $endTs <= $from) {
                continue;
            }

            $result[] = [$startTs, $endTs];
        }

        return $result;
    }

    /**
     * Le début de la série est TOUJOURS la première occurrence (RFC 5545 §3.8.5.3), même s'il ne correspond pas à la règle.
     *
     * @param  \Generator<int, CarbonImmutable>  $days
     * @return \Generator<int, CarbonImmutable>
     */
    private function withStart(\Generator $days, CarbonImmutable $start): \Generator
    {
        yield $start;

        foreach ($days as $day) {
            if ($day != $start) {
                yield $day;
            }
        }
    }

    /**
     * Jours candidats de la règle, en ordre croissant, à partir du début de la série.
     *
     * @param  array<string, string>  $rule
     * @return \Generator<int, CarbonImmutable>
     */
    private function days(array $rule, CarbonImmutable $first): \Generator
    {
        $interval = max(1, (int) ($rule['INTERVAL'] ?? 1));
        $byDay = $this->byDay($rule['BYDAY'] ?? '');
        $byMonthDay = array_values(array_filter(array_map('intval', explode(',', $rule['BYMONTHDAY'] ?? '')), fn (int $d): bool => $d !== 0));
        $byMonth = array_values(array_filter(array_map('intval', explode(',', $rule['BYMONTH'] ?? '')), fn (int $m): bool => $m >= 1 && $m <= 12));
        $startDay = $first->startOfDay();

        switch ($rule['FREQ']) {
            case 'DAILY':
                for ($k = 0; $k < self::MAX_PERIODS; $k++) {
                    $day = $startDay->addDays($k * $interval);

                    if ($this->matches($day, $byDay, $byMonthDay, $byMonth)) {
                        yield $day;
                    }
                }

                return;

            case 'WEEKLY':
                $weekStart = self::WEEKDAYS[$rule['WKST'] ?? 'MO'] ?? 1;
                $weekdays = $byDay === [] ? [$first->isoWeekday()] : array_values(array_unique(array_map(fn (array $entry): int => $entry[1], $byDay)));
                usort($weekdays, fn (int $a, int $b): int => (($a - $weekStart + 7) % 7) <=> (($b - $weekStart + 7) % 7));
                $base = $startDay->subDays(($first->isoWeekday() - $weekStart + 7) % 7);

                for ($k = 0; $k < self::MAX_PERIODS; $k++) {
                    foreach ($weekdays as $weekday) {
                        $day = $base->addDays($k * $interval * 7 + (($weekday - $weekStart + 7) % 7));

                        if ($day >= $startDay && ($byMonth === [] || in_array($day->month, $byMonth, true))) {
                            yield $day;
                        }
                    }
                }

                return;

            case 'MONTHLY':
                for ($k = 0; $k < self::MAX_PERIODS; $k++) {
                    $month = $startDay->startOfMonth()->addMonthsNoOverflow($k * $interval);

                    if ($byMonth !== [] && ! in_array($month->month, $byMonth, true)) {
                        continue;
                    }

                    foreach ($this->monthDays($month, $byDay, $byMonthDay, $first->day) as $day) {
                        if ($day >= $startDay) {
                            yield $day;
                        }
                    }
                }

                return;

            default: // YEARLY
                $months = $byMonth === [] ? [$first->month] : $byMonth;
                sort($months);

                for ($k = 0; $k < self::MAX_PERIODS; $k++) {
                    foreach ($months as $monthNumber) {
                        $month = CarbonImmutable::create($first->year + $k * $interval, $monthNumber, 1, 0, 0, 0, $first->getTimezone());

                        foreach ($this->monthDays($month, $byDay, $byMonthDay, $first->day) as $day) {
                            if ($day >= $startDay) {
                                yield $day;
                            }
                        }
                    }
                }
        }
    }

    /**
     * @param  list<array{0: int, 1: int}>  $byDay
     * @param  list<int>  $byMonthDay
     * @param  list<int>  $byMonth
     */
    private function matches(CarbonImmutable $day, array $byDay, array $byMonthDay, array $byMonth): bool
    {
        if ($byDay !== [] && ! in_array($day->isoWeekday(), array_map(fn (array $entry): int => $entry[1], $byDay), true)) {
            return false;
        }

        if ($byMonthDay !== [] && ! in_array($day->day, array_map(fn (int $d): int => $d > 0 ? $d : $day->daysInMonth + $d + 1, $byMonthDay), true)) {
            return false;
        }

        return $byMonth === [] || in_array($day->month, $byMonth, true);
    }

    /**
     * Jours d'un mois retenus par la règle (jours du mois, ou n-ième jour de semaine). Les jours inexistants (31 février) sont ignorés.
     *
     * @param  list<array{0: int, 1: int}>  $byDay  [rang, jour ISO]
     * @param  list<int>  $byMonthDay
     * @return list<CarbonImmutable>
     */
    private function monthDays(CarbonImmutable $month, array $byDay, array $byMonthDay, int $defaultDay): array
    {
        $numbers = [];
        $last = $month->daysInMonth;

        if ($byDay !== []) {
            foreach ($byDay as [$nth, $weekday]) {
                for ($d = 1; $d <= $last; $d++) {
                    if ((int) $month->setDay($d)->isoWeekday() !== $weekday) {
                        continue;
                    }

                    $ordinal = intdiv($d - 1, 7) + 1;
                    $fromEnd = -(intdiv($last - $d, 7) + 1);

                    if ($nth === 0 || $nth === $ordinal || $nth === $fromEnd) {
                        $numbers[] = $d;
                    }
                }
            }
        } else {
            foreach ($byMonthDay === [] ? [$defaultDay] : $byMonthDay as $d) {
                $d = $d > 0 ? $d : $last + $d + 1;

                if ($d >= 1 && $d <= $last) {
                    $numbers[] = $d;
                }
            }
        }

        $numbers = array_values(array_unique($numbers));
        sort($numbers);

        return array_map(fn (int $d): CarbonImmutable => $month->setDay($d)->startOfDay(), $numbers);
    }

    /**
     * @return list<array{0: int, 1: int}> [rang, jour ISO]
     */
    private function byDay(string $value): array
    {
        $entries = [];

        foreach (array_filter(explode(',', $value)) as $token) {
            if (preg_match('/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', trim($token), $m) === 1) {
                $entries[] = [(int) $m[1], self::WEEKDAYS[$m[2]]];
            }
        }

        return $entries;
    }

    private function until(?string $until, DateTimeZone $tz): ?int
    {
        if ($until === null || $until === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{8}$/', $until) === 1) {
                return CarbonImmutable::createFromFormat('Ymd', $until, $tz)->endOfDay()->getTimestamp();
            }

            return str_ends_with($until, 'Z')
                ? CarbonImmutable::createFromFormat('Ymd\THis\Z', $until, new DateTimeZone('UTC'))->getTimestamp()
                : CarbonImmutable::createFromFormat('Ymd\THis', $until, $tz)->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
