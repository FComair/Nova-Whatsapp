<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\PastStart;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Pas de rendez-vous dans le passé : à la création et au déplacement, d'après l'heure du site (et jamais celle du serveur ou du
 * navigateur). Lundi 1er juin 2026, 08:00 UTC = 10:00 à Bruxelles (heure d'été).
 */
function createdIn(Organization $org, callable $callback): mixed
{
    return app(TenantContext::class)->run($org->id, $callback);
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->payload = fn (array $overrides = []): array => [
        'location_id' => $this->location->id,
        'service_id' => $this->service->id,
        'practitioner_id' => $this->practitioner->id,
        'date' => '2026-06-01',
        'time' => '10:30',
        'phone' => '+32 470 12 34 56',
        'name' => 'Client Test',
        ...$overrides,
    ];
    $this->message = 'Cette heure est déjà passée. Choisissez une heure à venir.';
    $this->bookingCount = fn (): int => createdIn($this->org, fn () => Booking::query()->count());
});

describe('création', function (): void {
    it('refuse une heure déjà passée aujourd\'hui, avec un message clair et sans rien enregistrer', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '09:30']))->assertSessionHasErrors(['time' => $this->message]);

        expect(($this->bookingCount)())->toBe(0);
    });

    it('refuse un jour passé', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['date' => '2026-05-29']))->assertSessionHasErrors(['time' => $this->message]);

        expect(($this->bookingCount)())->toBe(0);
    });

    it('accepte une heure plus tard aujourd\'hui', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '10:30']))->assertSessionHasNoErrors();

        expect(($this->bookingCount)())->toBe(1);
    });

    it('accepte la minute en cours, refuse la minute d\'avant', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-06-01 08:30:40', 'UTC'));

        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '10:30']))->assertSessionDoesntHaveErrors('time');
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '10:29']))->assertSessionHasErrors(['time' => $this->message]);
    });

    it('accepte un jour à venir, même à une heure qui serait passée aujourd\'hui', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['date' => '2026-06-02', 'time' => '09:00']))->assertSessionHasNoErrors();

        expect(($this->bookingCount)())->toBe(1);
    });

    it('refuse une heure qui passe pendant que le formulaire reste ouvert', function (): void {
        $payload = ($this->payload)(['time' => '10:30']);

        // Choisie à 10:00, soumise à 10:45 (heure du site).
        $this->travelTo(CarbonImmutable::parse('2026-06-01 08:45:00', 'UTC'));
        ($this->as)()->post('/planning/rendez-vous', $payload)->assertSessionHasErrors(['time' => $this->message]);

        expect(($this->bookingCount)())->toBe(0);
    });

    it('garde les autres règles de réservation intactes (horaires d\'ouverture, créneau pris)', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '20:00']))->assertSessionHasErrors('slot')->assertSessionDoesntHaveErrors('time');

        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '11:00']))->assertSessionHasNoErrors();
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '11:00', 'phone' => '+32 470 99 88 77']))->assertSessionHasErrors('slot');
    });

    it('suit l\'heure du site au changement de jour, pas la date UTC', function (): void {
        // 23:30 UTC le 1er juin = 01:30 le 2 juin à Bruxelles.
        $this->travelTo(CarbonImmutable::parse('2026-06-01 23:30:00', 'UTC'));

        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['date' => '2026-06-02', 'time' => '01:00']))->assertSessionHasErrors(['time' => $this->message]);
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['date' => '2026-06-02', 'time' => '02:00']))->assertSessionDoesntHaveErrors('time');
        // Le 1er juin est déjà fini pour le site, même si c'est encore le 1er juin en UTC.
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['date' => '2026-06-01', 'time' => '23:45']))->assertSessionHasErrors(['time' => $this->message]);
    });

    it('utilise le fuseau du site, quel qu\'il soit', function (): void {
        $this->asOwner(fn () => Location::query()->whereKey($this->location->id)->update(['timezone' => 'Pacific/Auckland']));

        // 08:00 UTC = 20:00 à Auckland.
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '19:00']))->assertSessionHasErrors(['time' => $this->message]);
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '21:00']))->assertSessionDoesntHaveErrors('time');

        $this->asOwner(fn () => Location::query()->whereKey($this->location->id)->update(['timezone' => 'America/Los_Angeles']));

        // 08:00 UTC = 01:00 à Los Angeles : 02:00 est à venir, 00:30 est passé.
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '00:30']))->assertSessionHasErrors(['time' => $this->message]);
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '02:00']))->assertSessionDoesntHaveErrors('time');
    });

    it('rend le message dans la langue de l\'utilisateur', function (): void {
        expect(__('ui.planning.errors.past_time', [], 'en'))->toBe('This time has already passed. Choose a future time.')
            ->and(__('ui.planning.errors.past_time', [], 'nl'))->toBe('Dit tijdstip is al voorbij. Kies een tijdstip in de toekomst.')
            ->and(__('ui.planning.errors.past_time', [], 'fr'))->toBe($this->message);
    });
});

describe('déplacement', function (): void {
    beforeEach(function (): void {
        $this->bookAt = fn (string $start): Booking => createdIn($this->org, fn () => app(BookingCore::class)->create($this->org, $this->request($start)));
    });

    it('refuse de déplacer un rendez-vous vers une heure passée', function (): void {
        $booking = ($this->bookAt)('2026-06-01 15:00');

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-06-01', 'time' => '09:30'])->assertSessionHasErrors(['time' => $this->message]);

        expect(createdIn($this->org, fn () => $booking->fresh()->starts_at)->equalTo($this->brussels('2026-06-01 15:00')))->toBeTrue();
    });

    it('déplace vers une heure à venir', function (): void {
        $booking = ($this->bookAt)('2026-06-01 15:00');

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-06-02', 'time' => '09:00'])->assertSessionHasNoErrors();

        expect(createdIn($this->org, fn () => $booking->fresh()->starts_at)->equalTo($this->brussels('2026-06-02 09:00')))->toBeTrue();
    });

    it('ne contrôle pas l\'horaire d\'un rendez-vous déjà passé tant qu\'on ne le change pas', function (): void {
        $booking = ($this->bookAt)('2026-06-01 09:00');
        $practitioner = $this->practitioner;

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-06-01', 'time' => '09:00', 'practitioner_id' => $practitioner->id])->assertSessionDoesntHaveErrors('time');
        // Changer seulement le praticien (autre route) ne touche pas non plus à l'horaire.
        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/praticien", ['practitioner_id' => $practitioner->id])->assertSessionDoesntHaveErrors('time');
    });

    it('contrôle dès que la date ou l\'heure d\'un rendez-vous passé change', function (): void {
        $booking = ($this->bookAt)('2026-06-01 09:00');

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-06-01', 'time' => '09:15'])->assertSessionHasErrors(['time' => $this->message]);
        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-05-31', 'time' => '09:00'])->assertSessionHasErrors(['time' => $this->message]);
    });
});

describe('règle', function (): void {
    it('compare des instants, à la minute près', function (): void {
        $now = CarbonImmutable::parse('2026-06-01 08:30:40', 'UTC');

        expect(PastStart::isPast(CarbonImmutable::parse('2026-06-01 08:29:59', 'UTC'), $now))->toBeTrue()
            ->and(PastStart::isPast(CarbonImmutable::parse('2026-06-01 08:30:00', 'UTC'), $now))->toBeFalse()
            ->and(PastStart::isPast(CarbonImmutable::parse('2026-06-01 10:30', 'Europe/Brussels'), $now))->toBeFalse()
            ->and(PastStart::isPast(CarbonImmutable::parse('2026-06-01 10:29', 'Europe/Brussels'), $now))->toBeTrue();
    });

    it('traverse le passage à l\'heure d\'été sans décalage', function (): void {
        // Bruxelles passe à l'heure d'été le 29 mars 2026 à 02:00 : 01:30 UTC +01 = 00:30 UTC, 03:30 +02 = 01:30 UTC.
        $now = CarbonImmutable::parse('2026-03-29 01:00:00', 'UTC');

        expect(PastStart::isPast(CarbonImmutable::parse('2026-03-29 03:00', 'Europe/Brussels'), $now))->toBeFalse()
            ->and(PastStart::isPast(CarbonImmutable::parse('2026-03-29 01:30', 'Europe/Brussels'), $now))->toBeTrue();
    });
});
