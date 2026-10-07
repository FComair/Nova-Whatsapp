<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Support;

use App\Modules\Calendars\Models\ExternalCalendar;
use DOMDocument;
use DOMXPath;

/**
 * Lit un calendrier externe : fichier ICS (abonnement) ou CalDAV (REPORT calendar-query sur la période utile).
 * Renvoie du texte iCalendar, à passer à `IcsParser`.
 */
final class CalendarFetcher
{
    public function __construct(private readonly SafeHttp $http) {}

    /**
     * @param  int  $from  secondes Unix
     * @param  int  $to  secondes Unix
     *
     * @throws CalendarFetchFailed
     */
    public function fetch(ExternalCalendar $calendar, int $from, int $to): string
    {
        return $calendar->kind === ExternalCalendar::CALDAV ? $this->caldav($calendar, $from, $to) : $this->ics($calendar);
    }

    private function ics(ExternalCalendar $calendar): string
    {
        $response = $this->http->send('GET', $calendar->url_enc, ['Accept' => 'text/calendar, */*;q=0.5']);
        $this->assertOk($response->status());

        $body = $response->body();

        if (! str_contains($body, 'BEGIN:VCALENDAR')) {
            throw new CalendarFetchFailed('invalid_ics');
        }

        return $body;
    }

    private function caldav(ExternalCalendar $calendar, int $from, int $to): string
    {
        $range = sprintf('<c:time-range start="%s" end="%s"/>', gmdate('Ymd\THis\Z', $from), gmdate('Ymd\THis\Z', $to));
        $xml = '<?xml version="1.0" encoding="utf-8"?><c:calendar-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><c:calendar-data/></d:prop>'
            .'<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'.$range.'</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';

        $response = $this->http->send('REPORT', $calendar->url_enc, ['Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8'], $xml, $calendar->username_enc, $calendar->password_enc);
        $this->assertOk($response->status());

        return $this->calendarData($response->body());
    }

    /**
     * Extrait les blocs `calendar-data` d'une réponse multistatus, sans résolution d'entités externes ni accès réseau.
     */
    private function calendarData(string $xml): string
    {
        $dom = new DOMDocument;

        if (! @$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA) || $dom->doctype !== null) {
            throw new CalendarFetchFailed('invalid_ics');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        $chunks = [];

        foreach ($xpath->query('//c:calendar-data') ?: [] as $node) {
            $chunks[] = trim((string) $node->textContent);
        }

        return implode("\r\n", $chunks);
    }

    private function assertOk(int $status): void
    {
        if ($status === 401 || $status === 403) {
            throw new CalendarFetchFailed('http_'.$status);
        }

        if ($status >= 500) {
            throw new CalendarFetchFailed('http_5xx');
        }

        if ($status < 200 || $status >= 300) {
            throw new CalendarFetchFailed('http_'.$status);
        }
    }
}
