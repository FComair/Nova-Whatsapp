<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Catalog\Models\BusinessHour;
use App\Modules\Customers\Models\Customer;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Planning du dashboard (flowchart 2B) : lecture par jour, création, déplacement, annulation, résultat et paiement.
 */
function inOrg(Organization $org, callable $callback): mixed
{
    return app(TenantContext::class)->run($org->id, $callback);
}

beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();

    $this->book = fn (string $start, array $extra = []): Booking => inOrg($this->org, fn () => app(BookingCore::class)->create($this->org, $this->request($start, key: $extra['key'] ?? null)));
    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);

    $this->payload = fn (array $overrides = []): array => [
        'location_id' => $this->location->id,
        'service_id' => $this->service->id,
        'practitioner_id' => $this->practitioner->id,
        'date' => '2026-06-02',
        'time' => '10:00',
        'phone' => '+32 470 12 34 56',
        'name' => 'Client Test',
        ...$overrides,
    ];

    // Un second utilisateur par rôle, créé avec le rôle propriétaire de la base.
    $this->member = fn (string $role, ?Practitioner $practitioner = null): User => $this->asOwner(function () use ($role, $practitioner): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);
        $practitioner?->forceFill(['user_id' => $user->id])->save();

        return $user;
    });
});

describe('lecture', function (): void {
    it('affiche la semaine (lundi → dimanche) avec ses horaires d\'ouverture et les rendez-vous', function (): void {
        ($this->book)('2026-06-02 10:00');

        ($this->as)()->get('/planning?date=2026-06-02')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Planning/Index')
            ->where('date', '2026-06-02')
            ->where('location.id', $this->location->id)
            ->where('week.start', '2026-06-01')
            ->where('week.end', '2026-06-07')
            ->has('week.days', 7)
            ->where('week.days.1.date', '2026-06-02')
            ->where('week.days.1.open', [['start' => 540, 'end' => 1020]])
            ->has('practitioners', 1)
            ->where('practitioners.0.schedule.2', [['start' => 540, 'end' => 1020]])
            ->has('bookings', 1)
            ->where('bookings.0.date', '2026-06-02')
            ->where('bookings.0.starts', '10:00')
            ->where('bookings.0.ends', '10:30')
            ->where('bookings.0.start_minute', 600)
            ->where('bookings.0.customer.phone', '+32470123456')
            ->where('range', ['from' => 420, 'to' => 1140])
            ->has('services', 1));
    });

    it('n\'affiche pas les rendez-vous d\'une autre semaine ni les annulés', function (): void {
        ($this->book)('2026-06-03 10:00');
        ($this->book)('2026-06-09 10:00', ['key' => 'semaine-suivante']);
        $cancelled = ($this->book)('2026-06-02 11:00', ['key' => 'annule']);
        inOrg($this->org, fn () => app(BookingCore::class)->cancel($this->org, $cancelled, Actor::user(null)));

        ($this->as)()->get('/planning?date=2026-06-02')->assertInertia(fn (Assert $page) => $page->has('bookings', 1)->where('bookings.0.date', '2026-06-03'));
        ($this->as)()->get('/planning?date=2026-06-09')->assertInertia(fn (Assert $page) => $page->where('week.start', '2026-06-08')->has('bookings', 1)->where('bookings.0.date', '2026-06-09'));
    });

    it('commence la semaine le lundi, y compris quand la date choisie est un dimanche', function (): void {
        ($this->as)()->get('/planning?date=2026-06-07')->assertInertia(fn (Assert $page) => $page->where('week.start', '2026-06-01')->where('week.end', '2026-06-07'));
        ($this->as)()->get('/planning?date=2026-06-08')->assertInertia(fn (Assert $page) => $page->where('week.start', '2026-06-08')->where('week.end', '2026-06-14'));
    });

    it('utilise le jour local du site (un rendez-vous tardif reste sur son jour)', function (): void {
        $this->asOwner(fn () => PractitionerSchedule::query()->where('weekday', 2)->update(['ends_at' => '23:00']));
        $this->asOwner(fn () => BusinessHour::query()->where('weekday', 2)->update(['ends_at' => '23:00']));
        ($this->book)('2026-06-02 22:30');

        ($this->as)()->get('/planning?date=2026-06-02')->assertInertia(fn (Assert $page) => $page->has('bookings', 1)->where('bookings.0.starts', '22:30')->where('bookings.0.date', '2026-06-02'));
        ($this->as)()->get('/planning?date=2026-06-09')->assertInertia(fn (Assert $page) => $page->has('bookings', 0));
    });

    it('retombe sur aujourd\'hui quand la date est invalide', function (): void {
        ($this->as)()->get('/planning?date=pas-une-date')->assertOk()->assertInertia(fn (Assert $page) => $page->where('date', '2026-06-01'));
        ($this->as)()->get('/planning?date=2026-02-31')->assertOk()->assertInertia(fn (Assert $page) => $page->where('date', '2026-06-01'));
    });

    it('exige d\'être connecté', function (): void {
        $this->get('/planning')->assertRedirect('/connexion');
    });
});

describe('création', function (): void {
    it('crée le rendez-vous et le client, avec la source « dashboard »', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['notes' => 'Première visite']))->assertRedirect()->assertSessionHas('status', 'Rendez-vous créé.');

        inOrg($this->org, function (): void {
            $booking = Booking::query()->sole();

            expect($booking->source)->toBe('dashboard')->and($booking->status)->toBe('confirmed')->and($booking->starts_at->equalTo($this->brussels('2026-06-02 10:00')))->toBeTrue()
                ->and($booking->notes_enc)->toBe('Première visite')
                ->and(BookingEvent::query()->where('actor_type', 'user')->where('action', 'created')->count())->toBe(1);
        });
    });

    it('retrouve un client existant par son numéro', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['phone' => '0470 12 34 56']))->assertSessionHasNoErrors();

        inOrg($this->org, fn () => expect(Customer::query()->count())->toBe(1)->and(Booking::query()->sole()->customer_id)->toBe($this->customer->id));
    });

    it('propose les 3 prochains créneaux quand le créneau vient d\'être pris (E10)', function (): void {
        ($this->book)('2026-06-02 10:00');

        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['phone' => '+32 470 99 88 77', 'time' => '10:15']))
            ->assertSessionHasErrors(['slot' => 'Ce créneau vient d\'être pris. Voici les prochains créneaux libres.'])
            ->assertSessionHas('suggestions', fn (array $suggestions): bool => count($suggestions) === 3 && isset($suggestions[0]['date'], $suggestions[0]['time'], $suggestions[0]['practitioner_id']));

        inOrg($this->org, fn () => expect(Booking::query()->count())->toBe(1));
    });

    it('refuse un créneau hors horaires', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '20:00']))
            ->assertSessionHasErrors(['slot' => 'Ce créneau est en dehors des horaires d\'ouverture ou de disponibilité du praticien.']);
    });

    it('refuse un client bloqué', function (): void {
        $this->asOwner(fn () => $this->customer->forceFill(['is_blocked' => true])->save());

        ($this->as)()->post('/planning/rendez-vous', ($this->payload)())->assertSessionHasErrors(['booking' => 'Ce client est bloqué : aucun rendez-vous ne peut lui être attribué.']);
    });

    it('valide les champs', function (array $override, string $field): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)($override))->assertSessionHasErrors($field);
    })->with([
        'téléphone invalide' => [['phone' => 'abc'], 'phone'],
        'date invalide' => [['date' => '2026-13-40'], 'date'],
        'heure invalide' => [['time' => '25:00'], 'time'],
        'service inconnu' => [['service_id' => '00000000-0000-4000-8000-000000000000'], 'service_id'],
        'notes trop longues' => [['notes' => str_repeat('a', 1001)], 'notes'],
    ]);

    it('respecte le fuseau du site', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '09:00']));

        inOrg($this->org, fn () => expect(Booking::query()->sole()->starts_at->format('H:i'))->toBe('07:00')); // 9 h à Bruxelles en juin = 7 h UTC
    });
});

describe('déplacement, annulation, résultat, paiement', function (): void {
    it('déplace un rendez-vous', function (): void {
        $booking = ($this->book)('2026-06-03 10:00');

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-06-04', 'time' => '15:00'])->assertSessionHas('status', 'Rendez-vous déplacé.');

        inOrg($this->org, fn () => expect(Booking::query()->find($booking->id)->starts_at->equalTo($this->brussels('2026-06-04 15:00')))->toBeTrue());
    });

    it('propose des créneaux quand le déplacement est impossible', function (): void {
        $booking = ($this->book)('2026-06-03 10:00');
        ($this->book)('2026-06-03 14:00', ['key' => 'k2']);

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-06-03', 'time' => '14:15'])->assertSessionHasErrors('slot')->assertSessionHas('suggestions');
    });

    it('annule avec un motif', function (): void {
        $booking = ($this->book)('2026-06-03 10:00');

        ($this->as)()->post("/planning/rendez-vous/{$booking->id}/annuler", ['reason' => 'Client malade'])->assertSessionHas('status', 'Rendez-vous annulé.');

        inOrg($this->org, fn () => expect(Booking::query()->find($booking->id))->status->toBe('cancelled_by_business')->cancellation_reason->toBe('Client malade'));
    });

    it('marque un rendez-vous passé comme terminé ou absent, jamais avant l\'heure', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)()->post("/planning/rendez-vous/{$booking->id}/resultat", ['outcome' => 'completed'])
            ->assertSessionHasErrors(['booking' => 'Cette action n\'est pas possible dans l\'état actuel du rendez-vous.']);

        $this->travelTo(now()->addDays(2));
        ($this->as)()->post("/planning/rendez-vous/{$booking->id}/resultat", ['outcome' => 'no_show'])->assertSessionHasNoErrors();

        inOrg($this->org, fn () => expect(Booking::query()->find($booking->id)->status)->toBe('no_show'));
    });

    it('refuse un résultat inconnu', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)()->post("/planning/rendez-vous/{$booking->id}/resultat", ['outcome' => 'teleported'])->assertSessionHasErrors('outcome');
    });

    it('enregistre le paiement sur place', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/paiement", ['payment_status' => 'partially_paid', 'amount' => '10,50', 'method' => 'cash'])->assertSessionHasNoErrors();

        inOrg($this->org, fn () => expect(Booking::query()->find($booking->id))->payment_status->toBe('partially_paid')->amount_collected_cents->toBe(1050)->payment_method->toBe('cash'));
    });

    it('refuse un montant invalide ou supérieur au prix', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/paiement", ['payment_status' => 'paid_onsite', 'amount' => 'beaucoup'])->assertSessionHasErrors(['amount' => 'Saisissez un montant valide.']);
        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/paiement", ['payment_status' => 'paid_onsite', 'amount' => '999'])
            ->assertSessionHasErrors(['booking' => 'Le montant encaissé est incohérent avec le prix du rendez-vous.']);
    });
});

describe('rôles', function (): void {
    it('permet au réceptionniste de gérer le planning mais masque les notes', function (): void {
        $receptionist = ($this->member)('receptionist');
        inOrg($this->org, fn () => app(BookingCore::class)->create($this->org, new NewBooking($this->location->id, $this->service->id, $this->practitioner->id, $this->customer->id, $this->brussels('2026-06-02 10:00'), 'dashboard', Actor::user(null), notes: 'Allergie')));

        ($this->as)($receptionist)->get('/planning?date=2026-06-02')->assertInertia(fn (Assert $page) => $page->where('seesNotes', false)->where('bookings.0.notes', null));
        ($this->as)()->get('/planning?date=2026-06-02')->assertInertia(fn (Assert $page) => $page->where('seesNotes', true)->where('bookings.0.notes', 'Allergie'));

        ($this->as)($receptionist)->post('/planning/rendez-vous', ($this->payload)(['time' => '14:00', 'phone' => '+32 470 55 44 33']))->assertSessionHasNoErrors();
    });

    it('limite le praticien à ses propres rendez-vous', function (): void {
        $marc = $this->asOwner(function (): Practitioner {
            $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']);
            DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $this->service->id]);
            PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '17:00']);

            return $practitioner;
        });
        $lea = ($this->member)('practitioner', $this->practitioner);

        $mine = ($this->book)('2026-06-02 10:00');
        $theirs = inOrg($this->org, fn () => app(BookingCore::class)->create($this->org, $this->request('2026-06-02 10:00', practitioner: $marc, key: 'marc')));

        ($this->as)($lea)->get('/planning?date=2026-06-02')->assertInertia(fn (Assert $page) => $page->has('practitioners', 1)->where('practitioners.0.id', $this->practitioner->id)->has('bookings', 1)->where('bookings.0.id', $mine->id));

        ($this->as)($lea)->post("/planning/rendez-vous/{$theirs->id}/annuler")->assertForbidden();
        ($this->as)($lea)->put("/planning/rendez-vous/{$theirs->id}/deplacer", ['date' => '2026-06-03', 'time' => '10:00'])->assertForbidden();
        ($this->as)($lea)->post('/planning/rendez-vous', ($this->payload)(['practitioner_id' => $marc->id, 'time' => '15:00']))->assertForbidden();
        ($this->as)($lea)->post("/planning/rendez-vous/{$mine->id}/annuler")->assertSessionHasNoErrors();
    });

    it('limite le responsable de site à ses sites', function (): void {
        $second = $this->asOwner(fn () => Location::factory()->create(['org_id' => $this->org->id, 'name' => 'Autre site']));
        $manager = ($this->member)('site_manager');
        $this->asOwner(function () use ($manager, $second): void {
            $memberId = OrgMember::query()->where('user_id', $manager->id)->value('id');
            DB::table('org_member_locations')->insert(['org_id' => $this->org->id, 'member_id' => $memberId, 'location_id' => $second->id]);
        });
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)($manager)->get('/planning')->assertInertia(fn (Assert $page) => $page->has('locations', 1)->where('location.id', $second->id));
        ($this->as)($manager)->get("/planning?location={$this->location->id}")->assertInertia(fn (Assert $page) => $page->where('location.id', $second->id));
        ($this->as)($manager)->post("/planning/rendez-vous/{$booking->id}/annuler")->assertForbidden();
        ($this->as)($manager)->post('/planning/rendez-vous', ($this->payload)())->assertForbidden();
    });

    it('donne au responsable sans site attribué l\'accès à tous les sites', function (): void {
        $manager = ($this->member)('site_manager');

        ($this->as)($manager)->get('/planning')->assertOk()->assertInertia(fn (Assert $page) => $page->has('locations', 1));
    });
});

describe('isolation et état du compte', function (): void {
    it('répond 404 pour le rendez-vous d\'une autre organisation', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');
        ['user' => $stranger, 'org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $this->signedIn($stranger, $otherOrg)->post("/planning/rendez-vous/{$booking->id}/annuler")->assertNotFound();
        $this->signedIn($stranger, $otherOrg)->get('/planning')->assertInertia(fn (Assert $page) => $page->has('bookings', 0));
    });

    it('refuse toute écriture quand le compte est suspendu, mais garde la lecture', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');
        $this->asOwner(fn () => $this->org->forceFill(['status' => 'suspended'])->save());

        ($this->as)()->get('/planning?date=2026-06-02')->assertOk();
        ($this->as)()->post("/planning/rendez-vous/{$booking->id}/annuler")->assertRedirect(route('account.state'));

        inOrg($this->org, fn () => expect(Booking::query()->find($booking->id)->status)->toBe('confirmed'));
    });
});

describe('langues', function (): void {
    it('affiche les messages dans la langue du compte', function (string $locale, string $message): void {
        $this->asOwner(fn () => $this->owner->forceFill(['locale' => $locale])->save());
        ($this->book)('2026-06-02 10:00');

        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['time' => '10:15', 'phone' => '+32 470 99 88 77']))->assertSessionHasErrors(['slot' => $message]);
    })->with([
        ['fr', 'Ce créneau vient d\'être pris. Voici les prochains créneaux libres.'],
        ['nl', 'Dit tijdstip is net bezet. Hier zijn de eerstvolgende vrije tijdstippen.'],
        ['en', 'This slot has just been taken. Here are the next free slots.'],
    ]);
});
