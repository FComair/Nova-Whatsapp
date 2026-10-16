<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Identity\Models\User;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use Illuminate\Support\Facades\DB;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Prévenir le client par WhatsApp quand le personnel crée, déplace ou annule un rendez-vous depuis le planning.
 * « Maintenant » = lundi 1er juin 2026, 10:00 à Bruxelles.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld(existing: $org);
    app(TenantContext::class)->clear();
    $this->asOwner(fn () => WhatsappAccount::query()->create(['org_id' => $this->org->id, 'phone_number_id' => 'PN-1', 'access_token_enc' => 'token-test']));
    $this->transport = app(WhatsappTransport::class);

    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);
    $this->book = fn (string $start = '2026-06-02 10:00') => app(TenantContext::class)->run($this->org->id, fn (): Booking => app(BookingCore::class)->create($this->org, new NewBooking(
        $this->location->id, $this->service->id, $this->practitioner->id, $this->customer->id, $this->brussels($start), 'dashboard', Actor::user(null), enforcePolicies: false,
    )));
    $this->payload = fn (array $overrides = []): array => [
        'location_id' => $this->location->id, 'service_id' => $this->service->id, 'practitioner_id' => $this->practitioner->id,
        'date' => '2026-06-02', 'time' => '10:00', 'phone' => '+32 470 12 34 56', 'name' => 'Client Test', ...$overrides,
    ];
    $this->params = fn (string $day, string $time): array => [$this->org->name, 'Coupe', $day, $time];
});

describe('création', function (): void {
    it('envoie la confirmation dans la langue du client quand la case est cochée', function (): void {
        $this->asOwner(fn () => $this->customer->forceFill(['language' => 'nl'])->save());

        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['notify' => true]))->assertSessionHas('status', 'Rendez-vous créé. Le client a été prévenu par WhatsApp.')->assertSessionMissing('warning');

        expect($this->transport->templates)->toHaveCount(1)
            ->and($this->transport->templates[0])->toMatchArray(['to' => '+32470123456', 'template' => 'appointment_confirmation', 'language' => 'nl'])
            ->and($this->transport->templates[0]['parameters'])->toBe(($this->params)('dinsdag 02/06/2026', '10:00 CEST'));
    });

    it('n\'envoie rien sans la case, ou si elle est décochée', function (array $override): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)($override))->assertSessionHas('status', 'Rendez-vous créé.')->assertSessionMissing('warning');

        expect($this->transport->templates)->toBe([]);
    })->with(['absente' => [[]], 'décochée' => [['notify' => false]]]);

    it('n\'envoie rien quand la création est refusée', function (): void {
        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['notify' => true, 'time' => '03:00']))->assertSessionHasErrors();

        expect($this->transport->templates)->toBe([]);
    });
});

describe('déplacement', function (): void {
    it('envoie le nouveau créneau', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-06-03', 'time' => '14:30', 'practitioner_id' => $this->practitioner->id, 'notify' => true])
            ->assertSessionHas('status', 'Rendez-vous déplacé. Le client a été prévenu par WhatsApp.');

        expect($this->transport->templates)->toHaveCount(1)
            ->and($this->transport->templates[0])->toMatchArray(['template' => 'appointment_rescheduled', 'language' => 'fr'])
            ->and($this->transport->templates[0]['parameters'])->toBe(($this->params)('mercredi 03/06/2026', '14:30 CEST'));
    });

    it('n\'envoie rien sans la case', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-06-03', 'time' => '14:30', 'practitioner_id' => $this->practitioner->id])->assertSessionHas('status', 'Rendez-vous déplacé.');

        expect($this->transport->templates)->toBe([]);
    });

    it('n\'envoie rien si le créneau n\'est pas libre', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');
        ($this->book)('2026-06-03 14:30');

        ($this->as)()->put("/planning/rendez-vous/{$booking->id}/deplacer", ['date' => '2026-06-03', 'time' => '14:30', 'practitioner_id' => $this->practitioner->id, 'notify' => true])->assertSessionHasErrors('slot');

        expect($this->transport->templates)->toBe([]);
    });
});

describe('annulation', function (): void {
    it('envoie l\'annulation', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)()->post("/planning/rendez-vous/{$booking->id}/annuler", ['reason' => 'Absence du praticien', 'notify' => true])->assertSessionHas('status', 'Rendez-vous annulé. Le client a été prévenu par WhatsApp.');

        expect($this->transport->templates)->toHaveCount(1)->and($this->transport->templates[0])->toMatchArray(['template' => 'appointment_cancelled'])
            ->and(DB::connection('pgsql_owner')->table('bookings')->where('id', $booking->id)->value('status'))->toBe('cancelled_by_business');
    });

    it('n\'envoie rien sans la case', function (): void {
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)()->post("/planning/rendez-vous/{$booking->id}/annuler", [])->assertSessionHas('status', 'Rendez-vous annulé.');

        expect($this->transport->templates)->toBe([]);
    });
});

describe('client injoignable', function (): void {
    it('prévient l\'utilisateur sans annuler l\'action', function (string $case): void {
        match ($case) {
            'désinscrit' => $this->asOwner(fn () => $this->customer->forceFill(['whatsapp_opted_out_at' => now()])->save()),
            'sans compte WhatsApp' => $this->asOwner(fn () => WhatsappAccount::query()->delete()),
            'panne de Meta' => $this->transport->failWith = '131000',
        };
        $booking = ($this->book)('2026-06-02 10:00');

        ($this->as)()->post("/planning/rendez-vous/{$booking->id}/annuler", ['notify' => true])
            ->assertSessionHas('status', 'Rendez-vous annulé.')
            ->assertSessionHas('warning', 'Le client n\'a pas pu être prévenu par WhatsApp (désinscrit ou injoignable) : contactez-le directement.');

        expect(DB::connection('pgsql_owner')->table('bookings')->where('id', $booking->id)->value('status'))->toBe('cancelled_by_business')->and($this->transport->templates)->toBe([]);
        $this->transport->failWith = null;
    })->with(['désinscrit', 'sans compte WhatsApp', 'panne de Meta']);

    it('garde la création quand la confirmation échoue', function (): void {
        $this->asOwner(fn () => $this->customer->forceFill(['whatsapp_opted_out_at' => now()])->save());

        ($this->as)()->post('/planning/rendez-vous', ($this->payload)(['notify' => true]))->assertSessionHas('warning');

        expect(DB::connection('pgsql_owner')->table('bookings')->where('status', 'confirmed')->count())->toBe(1);
    });
});

it('journalise le message envoyé au client', function (): void {
    $booking = ($this->book)('2026-06-02 10:00');

    ($this->as)()->post("/planning/rendez-vous/{$booking->id}/annuler", ['notify' => true]);

    $message = DB::connection('pgsql_owner')->table('whatsapp_messages')->where('customer_id', $this->customer->id)->where('direction', 'out')->sole();
    expect($message->type)->toBe('template')->and($message->status)->toBe('sent');
});
