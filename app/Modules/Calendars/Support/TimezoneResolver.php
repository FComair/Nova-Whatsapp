<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Support;

use DateTimeZone;
use Throwable;

/**
 * Traduit l'identifiant de fuseau d'un fichier iCalendar (TZID) en fuseau PHP.
 * Accepte les noms IANA (Europe/Brussels), les chemins qui les contiennent (« /softwarestudio.org/Olson_20011030_5/Europe/Paris »)
 * et les noms Windows d'Outlook (« Romance Standard Time »). Inconnu : le fuseau par défaut du site.
 */
final class TimezoneResolver
{
    private const WINDOWS = [
        'UTC' => 'UTC',
        'GMT Standard Time' => 'Europe/London',
        'Greenwich Standard Time' => 'Atlantic/Reykjavik',
        'W. Europe Standard Time' => 'Europe/Berlin',
        'Romance Standard Time' => 'Europe/Paris',
        'Central Europe Standard Time' => 'Europe/Budapest',
        'Central European Standard Time' => 'Europe/Warsaw',
        'E. Europe Standard Time' => 'Europe/Chisinau',
        'FLE Standard Time' => 'Europe/Kyiv',
        'GTB Standard Time' => 'Europe/Bucharest',
        'Russian Standard Time' => 'Europe/Moscow',
        'Turkey Standard Time' => 'Europe/Istanbul',
        'Eastern Standard Time' => 'America/New_York',
        'Central Standard Time' => 'America/Chicago',
        'Mountain Standard Time' => 'America/Denver',
        'Pacific Standard Time' => 'America/Los_Angeles',
        'Atlantic Standard Time' => 'America/Halifax',
        'SA Pacific Standard Time' => 'America/Bogota',
        'India Standard Time' => 'Asia/Kolkata',
        'China Standard Time' => 'Asia/Shanghai',
        'Tokyo Standard Time' => 'Asia/Tokyo',
        'Singapore Standard Time' => 'Asia/Singapore',
        'Arabian Standard Time' => 'Asia/Dubai',
        'AUS Eastern Standard Time' => 'Australia/Sydney',
    ];

    public function __construct(private readonly string $default) {}

    public function resolve(?string $tzid): DateTimeZone
    {
        if ($tzid === null || trim($tzid) === '') {
            return new DateTimeZone($this->default);
        }

        $tzid = trim($tzid, " \t\"");

        foreach ([$tzid, self::WINDOWS[$tzid] ?? null, $this->lastSegments($tzid, 2), $this->lastSegments($tzid, 3)] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            try {
                return new DateTimeZone($candidate);
            } catch (Throwable) {
                continue;
            }
        }

        return new DateTimeZone($this->default);
    }

    private function lastSegments(string $tzid, int $count): ?string
    {
        $parts = array_values(array_filter(explode('/', $tzid), fn (string $part): bool => $part !== ''));

        return count($parts) > $count ? implode('/', array_slice($parts, -$count)) : null;
    }
}
