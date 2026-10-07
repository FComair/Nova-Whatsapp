<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\AvailabilityService;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\Slot;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Catalog\Models\BusinessHourException;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Staff\Models\PractitionerTimeOff;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingScenarios;

uses(BookingScenarios::class);

/** @return list<string> heures locales de Bruxelles des créneaux (HH:MM) */
function localStarts(array $slots): array
{
    return array_map(fn (Slot $slot): string => $slot->startsAt->setTimezone('Europe/Brussels')->format('H:i'), $slots);
}

beforeEach(function (): void {
    $this->bookingWorld();
    $this->availability = app(AvailabilityService::class);
    $this->slots = fn (string $from, ?string $to = null, bool $policies = true) => $this->availability->slots($this->org, $this->location, $this->service, $from, $to ?? $from, null, $policies);
});

describe('horaires', function (): void {
    it('propose une grille de 15 minutes dans les horaires d\'ouverture', function (): void {
        $starts = localStarts(($this->slots)('2026-06-02'));

        expect($starts)->toHaveCount(31)   // 9:00 à 16:30 inclus, un créneau de 30 min toutes les 15 min
            ->and($starts[0])->toBe('09:00')
            ->and(end($starts))->toBe('16:30');
    });

    it('ne propose rien le week-end', function (): void {
        expect(($this->slots)('2026-06-06', '2026-06-07'))->toBe([]);
    });

    it('limite les créneaux à l\'intersection horaires du site et planning du praticien', function (): void {
        $this->asOwner(fn () => PractitionerSchedule::query()->where('weekday', 2)->update(['starts_at' => '13:00', 'ends_at' => '20:00']));

        $starts = localStarts(($this->slots)('2026-06-02'));

        expect($starts[0])->toBe('13:00')->and(end($starts))->toBe('16:30');
    });

    it('gère la pause déjeuner (deux plages)', function (): void {
        $this->asOwner(function (): void {
            BusinessHour::query()->where('weekday', 2)->delete();
            foreach ([['09:00', '12:00'], ['14:00', '17:00']] as [$start, $end]) {
                BusinessHour::query()->create(['org_id' => $this->org->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => $start, 'ends_at' => $end]);
            }
        });

        $starts = localStarts(($this->slots)('2026-06-02'));

        expect($starts)->not->toContain('12:00')->and($starts)->not->toContain('13:45')->toContain('11:30')->toContain('14:00');
    });

    it('ne propose rien pour un site sans horaires', function (): void {
        $this->asOwner(fn () => BusinessHour::query()->delete());

        expect(($this->slots)('2026-06-02'))->toBe([]);
    });
});

describe('exceptions du site', function (): void {
    it('ferme le site un jour férié', function (): void {
        $this->asOwner(fn () => BusinessHourException::query()->create(['org_id' => $this->org->id, 'location_id' => $this->location->id, 'date' => '2026-06-02', 'is_closed' => true]));

        expect(($this->slots)('2026-06-02'))->toBe([])->and(($this->slots)('2026-06-03'))->not->toBe([]);
    });

    it('remplace les horaires habituels lors d\'une ouverture exceptionnelle', function (): void {
        $this->asOwner(fn () => BusinessHourException::query()->create(['org_id' => $this->org->id, 'location_id' => $this->location->id, 'date' => '2026-06-02', 'is_closed' => false, 'starts_at' => '10:00', 'ends_at' => '12:00']));

        $starts = localStarts(($this->slots)('2026-06-02'));

        expect($starts[0])->toBe('10:00')->and(end($starts))->toBe('11:30');
    });
});

describe('praticien', function (): void {
    it('retire les indisponibilités (congé, absence)', function (): void {
        $this->asOwner(fn () => PractitionerTimeOff::query()->create([
            'org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'kind' => 'unavailable',
            'starts_at' => $this->brussels('2026-06-02 12:00'), 'ends_at' => $this->brussels('2026-06-02 14:00'),
        ]));

        $starts = localStarts(($this->slots)('2026-06-02'));

        expect($starts)->toContain('11:30')->not->toContain('11:45')->not->toContain('13:45')->toContain('14:00');
    });

    it('ajoute une disponibilité exceptionnelle, sans dépasser l\'ouverture du site', function (): void {
        // Samedi : le site est fermé, donc rien. Mardi soir : le site ferme à 17 h.
        $this->asOwner(function (): void {
            foreach ([['2026-06-06 09:00', '2026-06-06 12:00'], ['2026-06-02 16:00', '2026-06-02 19:00']] as [$from, $to]) {
                PractitionerTimeOff::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'kind' => 'extra', 'starts_at' => $this->brussels($from), 'ends_at' => $this->brussels($to)]);
            }
        });

        $tuesday = localStarts(($this->slots)('2026-06-02'));

        expect(($this->slots)('2026-06-06'))->toBe([])->and(end($tuesday))->toBe('16:30');
    });

    it('utilise la durée personnalisée du praticien', function (): void {
        $this->asOwner(fn () => DB::table('practitioner_services')->update(['custom_duration_minutes' => 60]));

        $starts = localStarts(($this->slots)('2026-06-02'));

        expect(end($starts))->toBe('16:00')->and($starts)->toHaveCount(29);
    });

    it('ignore un praticien inactif ou sans le service', function (): void {
        $this->asOwner(fn () => $this->practitioner->forceFill(['status' => Practitioner::STATUS_INACTIVE])->save());

        expect(($this->slots)('2026-06-02'))->toBe([]);
    });

    it('propose un praticien par créneau et les trie par heure', function (): void {
        $other = $this->asOwner(function (): Practitioner {
            $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']);
            DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $this->service->id]);
            PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '10:00']);

            return $practitioner;
        });

        $slots = ($this->slots)('2026-06-02');

        expect(collect($slots)->where('practitionerId', $other->id))->toHaveCount(3)->and($slots[0]->startsAt->getTimestamp())->toBe($slots[1]->startsAt->getTimestamp());
    });
});

describe('réservations existantes', function (): void {
    it('retire le créneau pris et ses marges', function (): void {
        $this->asOwner(fn () => $this->service->forceFill(['buffer_before_minutes' => 10, 'buffer_after_minutes' => 15])->save());
        app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00'));

        $starts = localStarts(($this->slots)('2026-06-02'));

        // Occupé de 9:50 à 10:45 : un nouveau créneau doit finir (+ marge de 15) avant 9:50 ou commencer (− marge de 10) après 10:45.
        expect($starts)->toContain('09:00')->not->toContain('09:30')->not->toContain('10:30')->toContain('11:00');
    });

    it('libère le créneau d\'une réservation annulée', function (): void {
        $booking = app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00'));
        expect(localStarts(($this->slots)('2026-06-02')))->not->toContain('10:00');

        app(BookingCore::class)->cancel($this->org, $booking, Actor::user(null));

        expect(localStarts(($this->slots)('2026-06-02')))->toContain('10:00');
    });

    it('ignore un blocage en attente expiré mais respecte un blocage valide', function (): void {
        app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00', hold: true));
        expect(localStarts(($this->slots)('2026-06-02')))->not->toContain('10:00');

        $this->travel(11)->minutes();

        expect(localStarts(($this->slots)('2026-06-02')))->toContain('10:00');
    });

    it('permet de replacer un rendez-vous sur son propre créneau', function (): void {
        $booking = app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00'));

        $slots = $this->availability->slots($this->org, $this->location, $this->service, '2026-06-02', '2026-06-02', null, false, $booking->id);

        expect(localStarts($slots))->toContain('10:00');
    });
});

describe('politiques', function (): void {
    it('applique le délai minimum de réservation', function (): void {
        // Il est 10:00 à Bruxelles, délai de 2 h : aucun créneau avant 12:00 le jour même.
        $starts = localStarts(($this->slots)('2026-06-01'));

        expect($starts[0])->toBe('12:00');
    });

    it('applique l\'horizon maximal', function (): void {
        // Horizon de 60 jours depuis le 1er juin 10:00 : le 31 juillet 10:00 est la dernière limite.
        expect(($this->slots)('2026-07-31'))->not->toBe([]);
        expect(($this->slots)('2026-08-03'))->toBe([]);
    });

    it('ne les applique pas à la saisie par le personnel', function (): void {
        expect(localStarts(($this->slots)('2026-06-01', null, false))[0])->toBe('10:00')->and(($this->slots)('2026-08-03', null, false))->not->toBe([]);
    });

    it('renvoie les 3 prochains créneaux libres, ou rien sans horaires', function (): void {
        $this->asOwner(fn () => BusinessHour::query()->delete());

        expect($this->availability->nextSlots($this->org, $this->location, $this->service, 3))->toBe([]);

        $this->asOwner(function (): void {
            foreach (range(1, 5) as $weekday) {
                BusinessHour::query()->create(['org_id' => $this->org->id, 'location_id' => $this->location->id, 'weekday' => $weekday, 'starts_at' => '09:00', 'ends_at' => '17:00']);
            }
        });

        $next = $this->availability->nextSlots($this->org, $this->location, $this->service, 3);

        expect($next)->toHaveCount(3)->and(localStarts($next))->toBe(['12:00', '12:15', '12:30']);
    });
});

describe('heure d\'été et d\'hiver (Europe/Brussels)', function (): void {
    beforeEach(function (): void {
        $this->slotsOn = function (string $date, string $today): array {
            $this->travelTo(CarbonImmutable::parse($today, 'UTC'));

            return $this->availability->slots($this->org, $this->location, $this->service, $date, $date, null, false);
        };

        $this->asOwner(function (): void {
            foreach ([[7, '01:00', '05:00']] as [$weekday, $start, $end]) {
                BusinessHour::query()->create(['org_id' => $this->org->id, 'location_id' => $this->location->id, 'weekday' => $weekday, 'starts_at' => $start, 'ends_at' => $end]);
                PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'location_id' => $this->location->id, 'weekday' => $weekday, 'starts_at' => $start, 'ends_at' => $end]);
            }
        });
    });

    it('n\'offre que 3 heures de créneaux le jour du passage à l\'heure d\'été (2026-03-29)', function (): void {
        $slots = ($this->slotsOn)('2026-03-29', '2026-03-01');

        // 01:00 CET (00:00Z) → 05:00 CEST (03:00Z) = 3 h réelles = 6 créneaux de 30 min sur la grille de 15 min : 11.
        expect($slots)->toHaveCount(11)
            ->and(localStarts($slots))->not->toContain('02:00')->not->toContain('02:30')
            ->and($slots[3]->startsAt->format('H:i'))->toBe('00:45');   // 01:45 CET ; le suivant est 03:00 CEST
    });

    it('offre 5 heures de créneaux le jour du passage à l\'heure d\'hiver (2026-10-25)', function (): void {
        $slots = ($this->slotsOn)('2026-10-25', '2026-10-01');

        // 01:00 CEST (23:00Z la veille) → 05:00 CET (04:00Z) = 5 h réelles.
        expect($slots)->toHaveCount(19)
            ->and($slots[0]->startsAt->format('Y-m-d H:i'))->toBe('2026-10-24 23:00')
            ->and(collect($slots)->pluck('startsAt')->map->getTimestamp()->unique())->toHaveCount(19);
    });

    it('n\'a pas de créneau dans l\'heure qui n\'existe pas', function (): void {
        $starts = array_map(fn (Slot $s): string => $s->startsAt->utc()->format('H:i'), ($this->slotsOn)('2026-03-29', '2026-03-01'));

        // Aucun trou ni doublon : la grille en UTC est continue, de 00:00Z à 02:30Z.
        expect($starts[0])->toBe('00:00')->and(end($starts))->toBe('02:30');
    });
});

describe('isolation', function (): void {
    it('ne voit pas les réservations d\'une autre organisation', function (): void {
        app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00'));

        $other = $this->asOwner(fn () => Organization::factory()->create());

        app(TenantContext::class)->run($other->id, function (): void {
            expect(Booking::query()->count())->toBe(0);
        });

        expect(Booking::query()->count())->toBe(1);
    });
});
