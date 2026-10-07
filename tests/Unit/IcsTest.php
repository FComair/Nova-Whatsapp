<?php

declare(strict_types=1);

use App\Modules\Calendars\Support\IcsParser;
use App\Modules\Calendars\Support\RecurrenceExpander;
use Carbon\CarbonImmutable;

/**
 * Lecture iCalendar et dépliage des récurrences. Fuseau du site : Europe/Brussels.
 */
function icsBusy(string $body, string $from = '2026-06-01', string $to = '2026-08-31', ?RecurrenceExpander $expander = null): array
{
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n{$body}\r\nEND:VCALENDAR\r\n";
    $events = (new IcsParser('Europe/Brussels'))->events($ics);
    $expander ??= new RecurrenceExpander;
    $busy = $expander->busy($events, CarbonImmutable::parse($from, 'Europe/Brussels')->getTimestamp(), CarbonImmutable::parse($to, 'Europe/Brussels')->addDay()->getTimestamp());

    return array_map(fn (array $range): string => CarbonImmutable::createFromTimestamp($range[0], 'Europe/Brussels')->format('Y-m-d H:i').' → '.CarbonImmutable::createFromTimestamp($range[1], 'Europe/Brussels')->format('H:i'), $busy);
}

function vevent(string ...$lines): string
{
    return "BEGIN:VEVENT\r\nUID:test-".md5(implode('', $lines))."\r\n".implode("\r\n", $lines)."\r\nEND:VEVENT";
}

describe('événements simples', function (): void {
    it('lit un événement en UTC', function (): void {
        expect(icsBusy(vevent('DTSTART:20260602T080000Z', 'DTEND:20260602T090000Z')))->toBe(['2026-06-02 10:00 → 11:00']);
    });

    it('lit les fuseaux IANA, les chemins de fuseau et les noms Windows d\'Outlook', function (string $tzid): void {
        expect(icsBusy(vevent("DTSTART;TZID={$tzid}:20260602T100000", "DTEND;TZID={$tzid}:20260602T110000")))->toBe(['2026-06-02 10:00 → 11:00']);
    })->with(['Europe/Brussels', 'Romance Standard Time', '/softwarestudio.org/Olson_20011030_5/Europe/Paris', 'W. Europe Standard Time']);

    it('place un événement flottant dans le fuseau du site', function (): void {
        expect(icsBusy(vevent('DTSTART:20260602T140000', 'DTEND:20260602T150000')))->toBe(['2026-06-02 14:00 → 15:00']);
    });

    it('utilise DURATION quand DTEND est absent', function (): void {
        expect(icsBusy(vevent('DTSTART:20260602T080000Z', 'DURATION:PT1H30M')))->toBe(['2026-06-02 10:00 → 11:30']);
    });

    it('bloque une journée entière opaque, mais pas une journée « libre »', function (): void {
        expect(icsBusy(vevent('DTSTART;VALUE=DATE:20260603', 'DTEND;VALUE=DATE:20260604', 'TRANSP:OPAQUE')))->toBe(['2026-06-03 00:00 → 00:00'])
            ->and(icsBusy(vevent('DTSTART;VALUE=DATE:20260603', 'TRANSP:TRANSPARENT')))->toBe([]);
    });

    it('ignore les événements annulés et ceux marqués « libre »', function (): void {
        expect(icsBusy(vevent('DTSTART:20260602T080000Z', 'DTEND:20260602T090000Z', 'STATUS:CANCELLED')))->toBe([])
            ->and(icsBusy(vevent('DTSTART:20260602T080000Z', 'DTEND:20260602T090000Z', 'TRANSP:TRANSPARENT')))->toBe([]);
    });

    it('ignore les alarmes et déplie les lignes coupées', function (): void {
        $event = "BEGIN:VEVENT\r\nUID:a\r\nDTSTART:20260602T0800\r\n 00Z\r\nDTEND:20260602T090000Z\r\nSUMMARY:Un titre très long qui\r\n  continue sur la ligne suivante\r\nBEGIN:VALARM\r\nTRIGGER:-PT15M\r\nDTSTART:20200101T000000Z\r\nEND:VALARM\r\nEND:VEVENT";

        expect(icsBusy($event))->toBe(['2026-06-02 10:00 → 11:00']);
    });

    it('ne plante pas sur un contenu invalide et ne garde aucun texte d\'événement', function (): void {
        expect(icsBusy("garbage\r\nBEGIN:VEVENT\r\nDTSTART:pas-une-date\r\nEND:VEVENT"))->toBe([])
            ->and(array_keys(get_object_vars((new IcsParser('UTC'))->events("BEGIN:VEVENT\r\nUID:x\r\nDTSTART:20260602T080000Z\r\nDTEND:20260602T090000Z\r\nSUMMARY:Secret médical\r\nATTENDEE:mailto:a@b.c\r\nEND:VEVENT")[0])))->not->toContain('summary');
    });

    it('coupe les plages à la fenêtre demandée', function (): void {
        expect(icsBusy(vevent('DTSTART:20260531T220000Z', 'DTEND:20260601T020000Z'), '2026-06-01', '2026-06-01'))->toBe(['2026-06-01 00:00 → 04:00']);
    });

    it('fusionne les plages qui se chevauchent', function (): void {
        expect(icsBusy(vevent('DTSTART:20260602T080000Z', 'DTEND:20260602T090000Z')
            ."\r\n".vevent('DTSTART:20260602T083000Z', 'DTEND:20260602T100000Z')))->toBe(['2026-06-02 10:00 → 12:00']);
    });
});

describe('récurrences', function (): void {
    it('déplie un rythme hebdomadaire avec BYDAY et COUNT', function (): void {
        expect(icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260601T100000', 'DTEND;TZID=Europe/Brussels:20260601T110000', 'RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4')))->toBe([
            '2026-06-01 10:00 → 11:00', '2026-06-03 10:00 → 11:00', '2026-06-08 10:00 → 11:00', '2026-06-10 10:00 → 11:00',
        ]);
    });

    it('respecte INTERVAL et UNTIL', function (): void {
        expect(icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260601T100000', 'DTEND;TZID=Europe/Brussels:20260601T110000', 'RRULE:FREQ=WEEKLY;INTERVAL=2;UNTIL=20260630T235959Z')))->toBe([
            '2026-06-01 10:00 → 11:00', '2026-06-15 10:00 → 11:00', '2026-06-29 10:00 → 11:00',
        ]);
    });

    it('déplie un rythme quotidien avec filtre de jours', function (): void {
        expect(icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260601T090000', 'DTEND;TZID=Europe/Brussels:20260601T093000', 'RRULE:FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR;COUNT=6')))->toHaveCount(6)
            ->and(icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260601T090000', 'DTEND;TZID=Europe/Brussels:20260601T093000', 'RRULE:FREQ=DAILY;BYDAY=MO,TU,WE,TH,FR;COUNT=6'))[5])->toBe('2026-06-08 09:00 → 09:30');
    });

    it('saute les mois trop courts pour BYMONTHDAY=31', function (): void {
        $busy = icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260531T100000', 'DTEND;TZID=Europe/Brussels:20260531T110000', 'RRULE:FREQ=MONTHLY;BYMONTHDAY=31'), '2026-05-01', '2026-10-01');

        expect($busy)->toBe(['2026-05-31 10:00 → 11:00', '2026-07-31 10:00 → 11:00', '2026-08-31 10:00 → 11:00']);
    });

    it('gère le n-ième jour de semaine du mois (2e mardi, dernier vendredi)', function (): void {
        $second = icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260609T100000', 'DTEND;TZID=Europe/Brussels:20260609T110000', 'RRULE:FREQ=MONTHLY;BYDAY=2TU;COUNT=3'));
        $last = icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260626T100000', 'DTEND;TZID=Europe/Brussels:20260626T110000', 'RRULE:FREQ=MONTHLY;BYDAY=-1FR;COUNT=3'));

        expect($second)->toBe(['2026-06-09 10:00 → 11:00', '2026-07-14 10:00 → 11:00', '2026-08-11 10:00 → 11:00'])
            ->and($last)->toBe(['2026-06-26 10:00 → 11:00', '2026-07-31 10:00 → 11:00', '2026-08-28 10:00 → 11:00']);
    });

    it('déplie un événement annuel', function (): void {
        expect(icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20240615T100000', 'DTEND;TZID=Europe/Brussels:20240615T110000', 'RRULE:FREQ=YEARLY')))->toBe(['2026-06-15 10:00 → 11:00']);
    });

    it('retire les dates exclues (EXDATE), avec ou sans heure', function (): void {
        $rule = ['DTSTART;TZID=Europe/Brussels:20260601T100000', 'DTEND;TZID=Europe/Brussels:20260601T110000', 'RRULE:FREQ=DAILY;COUNT=4'];

        expect(icsBusy(vevent(...[...$rule, 'EXDATE;TZID=Europe/Brussels:20260602T100000'])))->toBe(['2026-06-01 10:00 → 11:00', '2026-06-03 10:00 → 11:00', '2026-06-04 10:00 → 11:00'])
            ->and(icsBusy(vevent(...[...$rule, 'EXDATE;VALUE=DATE:20260603'])))->toBe(['2026-06-01 10:00 → 11:00', '2026-06-02 10:00 → 11:00', '2026-06-04 10:00 → 11:00']);
    });

    it('remplace une occurrence par sa version modifiée (RECURRENCE-ID)', function (): void {
        $master = "BEGIN:VEVENT\r\nUID:serie\r\nDTSTART;TZID=Europe/Brussels:20260601T100000\r\nDTEND;TZID=Europe/Brussels:20260601T110000\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT";
        $moved = "BEGIN:VEVENT\r\nUID:serie\r\nRECURRENCE-ID;TZID=Europe/Brussels:20260602T100000\r\nDTSTART;TZID=Europe/Brussels:20260602T150000\r\nDTEND;TZID=Europe/Brussels:20260602T160000\r\nEND:VEVENT";

        expect(icsBusy($master."\r\n".$moved))->toBe(['2026-06-01 10:00 → 11:00', '2026-06-02 15:00 → 16:00', '2026-06-03 10:00 → 11:00']);
    });

    it('supprime une occurrence dont la version modifiée est annulée', function (): void {
        $master = "BEGIN:VEVENT\r\nUID:serie\r\nDTSTART;TZID=Europe/Brussels:20260601T100000\r\nDTEND;TZID=Europe/Brussels:20260601T110000\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT";
        $cancelled = "BEGIN:VEVENT\r\nUID:serie\r\nRECURRENCE-ID;TZID=Europe/Brussels:20260602T100000\r\nDTSTART;TZID=Europe/Brussels:20260602T100000\r\nSTATUS:CANCELLED\r\nEND:VEVENT";

        expect(icsBusy($master."\r\n".$cancelled))->toBe(['2026-06-01 10:00 → 11:00', '2026-06-03 10:00 → 11:00']);
    });

    it('garde l\'heure locale à travers le changement d\'heure (29 mars 2026)', function (): void {
        $busy = icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260323T100000', 'DTEND;TZID=Europe/Brussels:20260323T110000', 'RRULE:FREQ=WEEKLY;COUNT=3'), '2026-03-01', '2026-04-30');

        expect($busy)->toBe(['2026-03-23 10:00 → 11:00', '2026-03-30 10:00 → 11:00', '2026-04-06 10:00 → 11:00']);

        $events = (new IcsParser('Europe/Brussels'))->events("BEGIN:VEVENT\r\nUID:s\r\nDTSTART;TZID=Europe/Brussels:20260323T100000\r\nDTEND;TZID=Europe/Brussels:20260323T110000\r\nRRULE:FREQ=WEEKLY;COUNT=3\r\nEND:VEVENT");
        $ranges = (new RecurrenceExpander)->busy($events, CarbonImmutable::parse('2026-03-01', 'UTC')->getTimestamp(), CarbonImmutable::parse('2026-05-01', 'UTC')->getTimestamp());

        // 09:00 UTC avant le changement d'heure, 08:00 UTC après : l'événement suit l'heure du mur.
        expect(CarbonImmutable::createFromTimestampUTC($ranges[0][0])->format('H:i'))->toBe('09:00')->and(CarbonImmutable::createFromTimestampUTC($ranges[1][0])->format('H:i'))->toBe('08:00');
    });

    it('ne compte que la première occurrence pour une règle non prise en charge, et le signale', function (): void {
        $expander = new RecurrenceExpander;

        $busy = icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260601T100000', 'DTEND;TZID=Europe/Brussels:20260601T110000', 'RRULE:FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-1'), expander: $expander);

        expect($busy)->toBe(['2026-06-01 10:00 → 11:00'])->and($expander->unsupported)->toBe(1);
    });

    it('reste rapide pour une série quotidienne commencée il y a des années', function (): void {
        $start = microtime(true);
        $busy = icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20200101T090000', 'DTEND;TZID=Europe/Brussels:20200101T100000', 'RRULE:FREQ=DAILY'), '2026-06-01', '2026-06-03');

        expect($busy)->toBe(['2026-06-01 09:00 → 10:00', '2026-06-02 09:00 → 10:00', '2026-06-03 09:00 → 10:00'])->and(microtime(true) - $start)->toBeLessThan(2.0);
    });

    it('ne boucle pas sur une règle impossible', function (): void {
        expect(icsBusy(vevent('DTSTART;TZID=Europe/Brussels:20260601T100000', 'DTEND;TZID=Europe/Brussels:20260601T110000', 'RRULE:FREQ=DAILY;BYMONTH=2;BYMONTHDAY=31')))->toBe(['2026-06-01 10:00 → 11:00']);
    });
});
