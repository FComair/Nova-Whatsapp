<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Support;

use Carbon\CarbonImmutable;
use DateInterval;
use DateTimeZone;
use Throwable;

/**
 * Lecteur d'iCalendar (RFC 5545) limité à ce dont Rendivoo a besoin : les horaires occupés.
 *
 * Il extrait les VEVENT (identifiant, début, fin, récurrence, exceptions, annulation, disponibilité « libre ») et ignore tout
 * le reste, y compris les alarmes. Aucun texte d'événement n'est lu au-delà de ces champs : pas de titre, de lieu ni de participants.
 */
final class IcsParser
{
    private TimezoneResolver $timezones;

    public function __construct(private readonly string $defaultTimezone = 'UTC')
    {
        $this->timezones = new TimezoneResolver($defaultTimezone);
    }

    /**
     * @return list<IcsEvent>
     */
    public function events(string $ics, ?string $defaultTimezone = null): array
    {
        if ($defaultTimezone !== null) {
            $this->timezones = new TimezoneResolver($defaultTimezone);
        }

        $events = [];

        foreach ($this->components($ics) as $properties) {
            $event = $this->build($properties);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return list<array<string, list<array{params: array<string, string>, value: string}>>>
     */
    private function components(string $ics): array
    {
        $lines = preg_split('/\r\n|\n|\r/', (string) preg_replace('/\r?\n[ \t]/', '', $ics)) ?: [];
        $components = [];
        $current = null;
        $alarmDepth = 0;

        foreach ($lines as $line) {
            $upper = strtoupper(trim($line));

            if ($upper === 'BEGIN:VEVENT') {
                $current = [];
                $alarmDepth = 0;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($upper === 'END:VEVENT') {
                $components[] = $current;
                $current = null;

                continue;
            }

            if ($upper === 'BEGIN:VALARM') {
                $alarmDepth++;

                continue;
            }

            if ($upper === 'END:VALARM') {
                $alarmDepth = max(0, $alarmDepth - 1);

                continue;
            }

            if ($alarmDepth > 0 || ($parsed = $this->line($line)) === null) {
                continue;
            }

            $current[$parsed['name']][] = ['params' => $parsed['params'], 'value' => $parsed['value']];
        }

        return $components;
    }

    /**
     * @return array{name: string, params: array<string, string>, value: string}|null
     */
    private function line(string $line): ?array
    {
        $inQuotes = false;
        $colon = null;

        for ($i = 0, $length = strlen($line); $i < $length; $i++) {
            if ($line[$i] === '"') {
                $inQuotes = ! $inQuotes;
            } elseif ($line[$i] === ':' && ! $inQuotes) {
                $colon = $i;

                break;
            }
        }

        if ($colon === null) {
            return null;
        }

        $head = substr($line, 0, $colon);
        $parts = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $head) ?: [$head];
        $name = strtoupper(trim((string) array_shift($parts)));
        $params = [];

        foreach ($parts as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $params[strtoupper(trim($key))] = trim($value, '"');
        }

        return ['name' => $name, 'params' => $params, 'value' => trim(substr($line, $colon + 1))];
    }

    /**
     * @param  array<string, list<array{params: array<string, string>, value: string}>>  $p
     */
    private function build(array $p): ?IcsEvent
    {
        if (! isset($p['DTSTART'][0])) {
            return null;
        }

        $start = $this->moment($p['DTSTART'][0]['value'], $p['DTSTART'][0]['params']);

        if ($start === null) {
            return null;
        }

        [$startTs, $allDay, $tz] = $start;
        $endTs = null;

        if (isset($p['DTEND'][0])) {
            $end = $this->moment($p['DTEND'][0]['value'], $p['DTEND'][0]['params']);
            $endTs = $end === null ? null : $end[0];
        } elseif (isset($p['DURATION'][0])) {
            $endTs = $this->addDuration($startTs, $p['DURATION'][0]['value'], $tz);
        }

        $endTs ??= $allDay ? CarbonImmutable::createFromTimestamp($startTs, $tz)->addDay()->getTimestamp() : $startTs;
        $endTs = max($endTs, $startTs);

        $exdates = [];
        $exdateDays = [];

        foreach ($p['EXDATE'] ?? [] as $exdate) {
            foreach (explode(',', $exdate['value']) as $value) {
                $moment = $this->moment(trim($value), $exdate['params']);

                if ($moment === null) {
                    continue;
                }

                $moment[1] ? $exdateDays[] = CarbonImmutable::createFromTimestamp($moment[0], $moment[2])->format('Y-m-d') : $exdates[] = $moment[0];
            }
        }

        $recurrenceId = isset($p['RECURRENCE-ID'][0]) ? ($this->moment($p['RECURRENCE-ID'][0]['value'], $p['RECURRENCE-ID'][0]['params'])[0] ?? null) : null;

        return new IcsEvent(
            uid: trim($p['UID'][0]['value'] ?? 'sans-uid-'.$startTs),
            start: $startTs,
            end: $endTs,
            allDay: $allDay,
            timezone: $tz,
            rrule: isset($p['RRULE'][0]) ? $this->rule($p['RRULE'][0]['value']) : null,
            exdates: $exdates,
            exdateDays: $exdateDays,
            recurrenceId: $recurrenceId,
            cancelled: strtoupper($p['STATUS'][0]['value'] ?? '') === 'CANCELLED',
            transparent: strtoupper($p['TRANSP'][0]['value'] ?? 'OPAQUE') === 'TRANSPARENT',
        );
    }

    /**
     * @param  array<string, string>  $params
     * @return array{0: int, 1: bool, 2: DateTimeZone}|null instant, journée entière, fuseau
     */
    private function moment(string $value, array $params): ?array
    {
        try {
            if (($params['VALUE'] ?? '') === 'DATE' || preg_match('/^\d{8}$/', $value) === 1) {
                $tz = $this->timezones->resolve(null);

                return [CarbonImmutable::createFromFormat('Ymd', $value, $tz)->startOfDay()->getTimestamp(), true, $tz];
            }

            if (str_ends_with($value, 'Z')) {
                $tz = new DateTimeZone('UTC');

                return [CarbonImmutable::createFromFormat('Ymd\THis\Z', $value, $tz)->getTimestamp(), false, $tz];
            }

            $tz = $this->timezones->resolve($params['TZID'] ?? null);

            return [CarbonImmutable::createFromFormat('Ymd\THis', $value, $tz)->getTimestamp(), false, $tz];
        } catch (Throwable) {
            return null;
        }
    }

    private function addDuration(int $start, string $duration, DateTimeZone $tz): ?int
    {
        try {
            $negative = str_starts_with($duration, '-');
            $interval = new DateInterval(ltrim($duration, '+-'));
            $moment = CarbonImmutable::createFromTimestamp($start, $tz);

            return ($negative ? $moment->sub($interval) : $moment->add($interval))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function rule(string $rrule): array
    {
        $rule = [];

        foreach (explode(';', $rrule) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $rule[strtoupper(trim($key))] = strtoupper(trim($value));
        }

        return $rule;
    }
}
