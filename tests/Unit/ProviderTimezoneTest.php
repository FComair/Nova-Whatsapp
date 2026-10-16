<?php

declare(strict_types=1);

use App\Modules\Booking\Support\LocalTime;
use App\Modules\Tenancy\Support\TimezoneCatalog;
use Carbon\CarbonImmutable;

/**
 * Fuseau unique du prestataire : les heures saisies sont lues dans ce fuseau, enregistrées en UTC, puis relues dans ce fuseau.
 */
describe('heure locale du prestataire ⇄ UTC', function (): void {
    it('enregistre en UTC l’heure locale d’été (UTC+2) et d’hiver (UTC+1) de Bruxelles', function (): void {
        expect(LocalTime::toUtc('2026-07-01', '10:00', 'Europe/Brussels')->format('Y-m-d H:i'))->toBe('2026-07-01 08:00')
            ->and(LocalTime::toUtc('2026-01-15', '10:00', 'Europe/Brussels')->format('Y-m-d H:i'))->toBe('2026-01-15 09:00');
    });

    it('relit le même rendez-vous à la même heure locale, avant et après le changement d’heure', function (): void {
        foreach (['2026-03-28', '2026-03-29', '2026-03-30', '2026-10-24', '2026-10-25', '2026-10-26'] as $date) {
            $utc = LocalTime::toUtc($date, '15:00', 'Europe/Brussels');

            expect($utc->setTimezone('Europe/Brussels')->format('H:i'))->toBe('15:00', $date);
        }
    });

    it('garde la même durée réelle à travers le passage à l’heure d’été', function (): void {
        $start = LocalTime::toUtc('2026-03-29', '01:30', 'Europe/Brussels');
        $end = LocalTime::toUtc('2026-03-29', '03:30', 'Europe/Brussels');

        // 01:30 → 03:30 locale traverse le saut de 02:00 à 03:00 : une heure réelle seulement.
        expect(($end->getTimestamp() - $start->getTimestamp()) / 3600)->toBe(1.0);
    });

    it('refuse une heure qui n’existe pas (saut du printemps) et accepte les autres', function (): void {
        expect(LocalTime::exists('2026-03-29', '02:30', 'Europe/Brussels'))->toBeFalse()
            ->and(LocalTime::exists('2026-03-29', '03:30', 'Europe/Brussels'))->toBeTrue()
            ->and(LocalTime::exists('2026-03-29', '01:59', 'Europe/Brussels'))->toBeTrue()
            ->and(LocalTime::exists('2026-10-25', '02:30', 'Europe/Brussels'))->toBeTrue();
    });

    it('retient toujours la première occurrence d’une heure ambiguë (passage à l’heure d’hiver)', function (): void {
        // 02:30 existe deux fois le 25/10/2026 : 00:30 UTC (heure d’été) puis 01:30 UTC (heure d’hiver).
        expect(LocalTime::toUtc('2026-10-25', '02:30', 'Europe/Brussels')->format('Y-m-d H:i'))->toBe('2026-10-25 00:30');
    });

    it('donne la même heure UTC quel que soit le fuseau d’affichage du client final', function (): void {
        $utc = LocalTime::toUtc('2026-06-02', '10:00', 'Europe/Brussels');

        // Un client à Tokyo ou à New York réserve le même instant ; seul le prestataire fixe l’heure affichée.
        expect($utc->format('H:i'))->toBe('08:00')
            ->and($utc->setTimezone('Asia/Tokyo')->format('H:i'))->toBe('17:00')
            ->and($utc->setTimezone('America/New_York')->format('H:i'))->toBe('04:00')
            ->and($utc->setTimezone('Europe/Brussels')->format('H:i'))->toBe('10:00');
    });

    it('lit les créneaux d’un prestataire d’outre-mer dans son propre fuseau', function (): void {
        $utc = CarbonImmutable::parse('2026-06-02 10:00', 'Indian/Reunion')->utc();

        expect($utc->format('H:i'))->toBe('06:00');
    });
});

describe('fuseaux proposés selon le pays', function (): void {
    it('propose le fuseau principal en premier', function (): void {
        $catalog = new TimezoneCatalog;

        expect($catalog->forCountry('BE'))->toBe(['Europe/Brussels'])
            ->and($catalog->forCountry('FR')[0])->toBe('Europe/Paris')
            ->and($catalog->main('DE'))->toBe('Europe/Berlin');
    });

    it('laisse choisir parmi plusieurs fuseaux quand le pays en compte plusieurs', function (): void {
        $zones = (new TimezoneCatalog)->forCountry('FR');

        expect(count($zones))->toBeGreaterThan(1)->and($zones)->toContain('Indian/Reunion');
    });

    it('refuse un fuseau d’un autre pays', function (): void {
        $catalog = new TimezoneCatalog;

        expect($catalog->accepts('BE', 'Europe/Brussels'))->toBeTrue()
            ->and($catalog->accepts('BE', 'Europe/Paris'))->toBeFalse()
            ->and($catalog->accepts('BE', 'Not/AZone'))->toBeFalse()
            ->and($catalog->forCountry('xx!'))->toBe([]);
    });
});
