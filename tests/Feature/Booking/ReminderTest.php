<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Models\BookingPolicy;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\PolicyService;
use App\Modules\Booking\Services\ReminderService;
use App\Modules\Booking\Support\Actor;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingScenarios;

uses(BookingScenarios::class);

/**
 * Rappels WhatsApp : fenêtre d'envoi, une seule fois, langue du client, STOP, échecs, isolation.
 * « Maintenant » = lundi 1er juin 2026, 08:00 UTC ; le rendez-vous type est le mercredi 3 juin à 10:00 (Bruxelles).
 */
beforeEach(function (): void {
    $this->bookingWorld();
    $this->asOwner(fn () => WhatsappAccount::query()->create(['org_id' => $this->org->id, 'phone_number_id' => 'PN-1', 'access_token_enc' => 'token-test']));
    $this->transport = app(WhatsappTransport::class);
    $this->core = app(BookingCore::class);

    $this->book = fn (string $start = '2026-06-03 10:00', string $key = 'k1') => $this->core->create($this->org, $this->request($start, key: $key));
    $this->run = fn (): int => app(ReminderService::class)->sendDue($this->org);
    $this->event = fn (string $action): int => BookingEvent::query()->where('action', $action)->count();
});

describe('envoi', function (): void {
    it('envoie le modèle dans la fenêtre de 24 h, dans la langue du client', function (string $language, string $date): void {
        $this->asOwner(fn () => $this->customer->forceFill(['language' => $language])->save());
        ($this->book)();
        $this->travelTo(now()->setDate(2026, 6, 2)->setTime(9, 0));

        expect(($this->run)())->toBe(1);

        expect($this->transport->templates)->toBe([[
            'account' => 'PN-1',
            'to' => '+32470123456',
            'template' => 'appointment_reminder',
            'language' => $language,
            'parameters' => [$this->org->name, 'Coupe', $date, '10:00 CEST'],
        ]]);
    })->with([
        ['fr', 'mercredi 03/06/2026'],
        ['nl', 'woensdag 03/06/2026'],
        ['en', 'Wednesday 03/06/2026'],
    ]);

    it('utilise le français pour un client sans langue et journalise le message chiffré', function (): void {
        ($this->book)();
        $this->travelTo(now()->addDay());

        ($this->run)();

        expect($this->transport->templates[0]['language'])->toBe('fr');
        $message = WhatsappMessage::query()->sole();
        expect($message)->direction->toBe('out')->type->toBe('template')->status->toBe('sent')
            ->and($this->asOwner(fn () => DB::table('whatsapp_messages')->value('body_enc')))->not->toContain('appointment_reminder');
    });

    it('n\'envoie rien avant la fenêtre ni après le début du rendez-vous', function (): void {
        ($this->book)();

        expect(($this->run)())->toBe(0);                      // 50 h avant
        $this->travelTo(now()->setDate(2026, 6, 2)->setTime(7, 0));
        expect(($this->run)())->toBe(0);                      // 27 h avant, fenêtre de 24 h pas encore ouverte
        $this->travelTo(now()->setDate(2026, 6, 3)->setTime(9, 0));
        expect(($this->run)())->toBe(0);                      // rendez-vous déjà commencé (8 h UTC = 10 h locale)
        expect($this->transport->templates)->toBe([]);
    });

    it('n\'envoie qu\'un seul rappel, même en cas de passages répétés', function (): void {
        $booking = ($this->book)();
        $this->travelTo(now()->addDay());

        expect(($this->run)())->toBe(1)->and(($this->run)())->toBe(0);

        expect($this->transport->templates)->toHaveCount(1)->and(($this->event)('reminder_sent'))->toBe(1)
            ->and($booking->fresh()->reminder_sent_at)->not->toBeNull();
    });

    it('réserve le rappel de façon atomique', function (): void {
        $booking = ($this->book)();

        expect($this->core->claimReminder($booking))->toBeTrue()->and($this->core->claimReminder(Booking::query()->findOrFail($booking->id)))->toBeFalse();
    });

    it('ne rappelle pas un rendez-vous pris à l\'intérieur du délai de rappel', function (): void {
        // Pris maintenant pour demain 09:00 : le client vient de le réserver.
        ($this->book)('2026-06-02 09:00');

        expect(($this->run)())->toBe(0);
    });

    it('réenvoie un rappel pour le nouvel horaire après un déplacement', function (): void {
        $booking = ($this->book)();
        $this->travelTo(now()->addDay());
        ($this->run)();

        $this->core->move($this->org, $booking, $this->brussels('2026-06-03 15:00'), Actor::user(null), enforcePolicies: false);

        $this->travelTo(now()->addHours(6)); // le nouvel horaire (15:00) entre à son tour dans la fenêtre de 24 h

        expect(($this->run)())->toBe(1)->and($this->transport->templates)->toHaveCount(2)->and($this->transport->templates[1]['parameters'][3])->toBe('15:00');
    });
});

describe('cas où aucun rappel ne part', function (): void {
    it('respecte le STOP du client', function (): void {
        ($this->book)();
        $this->asOwner(fn () => $this->customer->forceFill(['whatsapp_opted_out_at' => now()])->save());
        $this->travelTo(now()->addDay());

        expect(($this->run)())->toBe(0)->and($this->transport->templates)->toBe([]);
    });

    it('respecte le réglage « aucun rappel » des politiques', function (): void {
        ($this->book)();
        app(PolicyService::class)->update($this->org, [...BookingPolicy::DEFAULTS, 'reminder_hours_before' => 0]);
        $this->travelTo(now()->addDay());

        expect(($this->run)())->toBe(0);
    });

    it('applique le délai des politiques', function (): void {
        ($this->book)();
        app(PolicyService::class)->update($this->org, [...BookingPolicy::DEFAULTS, 'reminder_hours_before' => 2]);

        $this->travelTo(now()->setDate(2026, 6, 3)->setTime(5, 0)); // 07:00 locale, rendez-vous à 10:00 : 3 h avant
        expect(($this->run)())->toBe(0);
        $this->travelTo(now()->setTime(6, 30));                     // 08:30 locale : 1 h 30 avant
        expect(($this->run)())->toBe(1);
    });

    it('ignore les rendez-vous annulés et en attente', function (): void {
        $cancelled = ($this->book)('2026-06-03 10:00', 'a');
        $this->core->cancel($this->org, $cancelled, Actor::user(null));
        $this->core->create($this->org, $this->request('2026-06-03 14:00', hold: true, key: 'b'));
        $this->travelTo(now()->addDay()->subMinutes(5));

        expect(($this->run)())->toBe(0);
    });

    it('ignore les comptes suspendus, en pause ou en suppression', function (string $status): void {
        ($this->book)();
        $this->asOwner(fn () => $this->org->forceFill(['status' => $status])->save());
        $this->org->refresh();
        $this->travelTo(now()->addDay());

        expect(($this->run)())->toBe(0);
    })->with(['suspended', 'paused', 'pending_deletion']);
});

describe('échecs', function (): void {
    it('libère le rappel en cas d\'échec puis réessaie, jusqu\'à 3 tentatives', function (): void {
        $booking = ($this->book)();
        $this->travelTo(now()->addDay());
        $this->transport->failWith = 'meta_131047';

        expect(($this->run)())->toBe(0)->and($booking->fresh())->reminder_sent_at->toBeNull()->reminder_attempts->toBe(1);

        ($this->run)();
        ($this->run)();
        ($this->run)();   // la quatrième tentative n'a pas lieu

        expect($booking->fresh()->reminder_attempts)->toBe(3)->and(($this->event)('reminder_failed'))->toBe(3);

        $this->transport->failWith = null;
        expect(($this->run)())->toBe(0);
    });

    it('réussit à la tentative suivante quand le problème disparaît', function (): void {
        ($this->book)();
        $this->travelTo(now()->addDay());
        $this->transport->failWith = 'network';
        ($this->run)();
        $this->transport->failWith = null;

        expect(($this->run)())->toBe(1);
    });

    it('consigne l\'échec sans numéro WhatsApp rattaché', function (): void {
        $booking = ($this->book)();
        $this->asOwner(fn () => WhatsappAccount::query()->delete());
        $this->travelTo(now()->addDay());

        expect(($this->run)())->toBe(0)->and($booking->fresh()->reminder_attempts)->toBe(1);
        expect(BookingEvent::query()->where('action', 'reminder_failed')->sole()->diff['error'])->toBe('no_account');
    });
});

describe('commande planifiée', function (): void {
    it('envoie les rappels de toutes les organisations, chacun sous son contexte', function (): void {
        ($this->book)();
        $this->travelTo(now()->addDay());
        app(TenantContext::class)->clear();

        $this->artisan('bookings:send-reminders')->expectsOutputToContain('1 rappel(s) envoyé(s).')->assertSuccessful();

        app(TenantContext::class)->set($this->org->id);
        expect($this->transport->templates)->toHaveCount(1)->and(($this->event)('reminder_sent'))->toBe(1);
    });
});
