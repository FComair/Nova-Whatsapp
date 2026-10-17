<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Calendars\Services\CalendarSync as Sync;
use App\Modules\Catalog\Models\Service;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Construit le fichier iCalendar des rendez-vous confirmés d'un praticien (7 jours passés, 180 jours à venir).
 * Aucune donnée de client : ni nom, ni téléphone, ni notes. Seuls figurent l'horaire et le nom du service.
 */
final class IcsFeedBuilder
{
    public function build(Organization $organization, Practitioner $practitioner): string
    {
        $locale = in_array($organization->locale, (array) config('rendivoo.locales'), true) ? $organization->locale : (string) config('app.fallback_locale');
        $now = CarbonImmutable::now();

        $bookings = Booking::query()->where('practitioner_id', $practitioner->id)->where('status', Booking::CONFIRMED)
            ->where('starts_at', '>=', $now->subDays(7))->where('starts_at', '<=', $now->addDays(180))->orderBy('starts_at')->get();
        $services = Service::query()->whereIn('id', $bookings->pluck('service_id'))->pluck('name', 'id');

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Rendivoo//Rendez-vous//FR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:'.$this->escape('Rendivoo · '.$practitioner->name)];

        foreach ($bookings as $booking) {
            array_push(
                $lines,
                'BEGIN:VEVENT',
                'UID:'.$booking->id.Sync::FEED_UID_SUFFIX,
                'DTSTAMP:'.$now->utc()->format('Ymd\THis\Z'),
                'DTSTART:'.$booking->starts_at->utc()->format('Ymd\THis\Z'),
                'DTEND:'.$booking->ends_at->utc()->format('Ymd\THis\Z'),
                'SUMMARY:'.$this->escape(__('ui.calendars.feed_event', ['service' => $services[$booking->service_id] ?? ''], $locale)),
                'STATUS:CONFIRMED',
                'TRANSP:OPAQUE',
                'END:VEVENT',
            );
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $lines))."\r\n";
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    /** Lignes de 75 octets au plus (RFC 5545 §3.1), sans couper un caractère multi-octets. */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';

        foreach (mb_str_split($line) as $char) {
            $limit = $out === '' ? 75 : 74;

            if (strlen($current.$char) > $limit) {
                $out .= ($out === '' ? '' : "\r\n ").$current;
                $current = '';
            }

            $current .= $char;
        }

        return $out."\r\n ".$current;
    }
}
