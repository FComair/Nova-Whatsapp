<?php

declare(strict_types=1);

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\BookingRuleViolation;
use App\Modules\Booking\Services\SlotUnavailable;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\BookingScenarios;

uses(BookingScenarios::class);

beforeEach(function (): void {
    $this->bookingWorld();
    $this->core = app(BookingCore::class);
    $this->admin = Actor::user(null);
});

function violation(callable $callback): ?string
{
    try {
        $callback();
    } catch (BookingRuleViolation $e) {
        return $e->rule;
    }

    return null;
}

describe('création', function (): void {
    it('crée une réservation confirmée avec prix, TVA et créneau bloqué', function (): void {
        $this->asOwner(fn () => $this->service->forceFill(['buffer_before_minutes' => 5, 'buffer_after_minutes' => 10])->save());

        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        expect($booking->status)->toBe('confirmed')
            ->and($booking->starts_at->equalTo($this->brussels('2026-06-02 10:00')))->toBeTrue()
            ->and($booking->ends_at->equalTo($this->brussels('2026-06-02 10:30')))->toBeTrue()
            ->and($booking->blocked_from->equalTo($this->brussels('2026-06-02 09:55')))->toBeTrue()
            ->and($booking->blocked_until->equalTo($this->brussels('2026-06-02 10:40')))->toBeTrue()
            ->and($booking->price_cents)->toBe(2500)
            ->and($booking->expires_at)->toBeNull();
    });

    it('utilise le prix et la durée propres au praticien', function (): void {
        $this->asOwner(fn () => DB::table('practitioner_services')->update(['custom_duration_minutes' => 45, 'custom_price_cents' => 3000]));

        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        expect($booking->price_cents)->toBe(3000)->and($booking->ends_at->equalTo($this->brussels('2026-06-02 10:45')))->toBeTrue();
    });

    it('journalise la création', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00', source: 'whatsapp'));

        $event = BookingEvent::query()->where('booking_id', $booking->id)->sole();

        expect($event->action)->toBe('created')->and($event->actor_type)->toBe('user')->and($event->diff['source'])->toBe('whatsapp');
    });

    it('crée un blocage en attente avec échéance', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00', hold: true));

        expect($booking->status)->toBe('pending')->and($booking->expires_at->equalTo(now()->addMinutes(10)))->toBeTrue();
    });

    it('chiffre les notes au repos', function (): void {
        $request = new NewBooking($this->location->id, $this->service->id, $this->practitioner->id, $this->customer->id, $this->brussels('2026-06-02 10:00'), 'dashboard', $this->admin, notes: 'Allergie au latex');
        $booking = $this->core->create($this->org, $request);

        $raw = DB::table('bookings')->where('id', $booking->id)->value('notes_enc');

        expect($raw)->not->toContain('latex')->and($booking->fresh()->notes_enc)->toBe('Allergie au latex');
    });

    it('est idempotente : la même clé renvoie la même réservation', function (): void {
        $first = $this->core->create($this->org, $this->request('2026-06-02 10:00', key: 'wa-msg-1'));
        $second = $this->core->create($this->org, $this->request('2026-06-02 10:00', key: 'wa-msg-1'));

        expect($second->id)->toBe($first->id)->and(Booking::query()->count())->toBe(1)->and(BookingEvent::query()->count())->toBe(1);
    });
});

describe('règles', function (): void {
    it('refuse un client bloqué', function (): void {
        $this->asOwner(fn () => $this->customer->forceFill(['is_blocked' => true])->save());

        expect(violation(fn () => $this->core->create($this->org, $this->request('2026-06-02 10:00'))))->toBe(BookingRuleViolation::CUSTOMER_BLOCKED);
    });

    it('refuse un service inactif', function (): void {
        $this->asOwner(fn () => $this->service->forceFill(['is_active' => false])->save());

        expect(violation(fn () => $this->core->create($this->org, $this->request('2026-06-02 10:00'))))->toBe(BookingRuleViolation::SERVICE_UNAVAILABLE);
    });

    it('refuse un praticien qui ne réalise pas le service ou qui est inactif', function (): void {
        $other = $this->asOwner(fn () => Practitioner::query()->create(['org_id' => $this->org->id, 'name' => 'Marc', 'color' => '#2A5BD7']));

        expect(violation(fn () => $this->core->create($this->org, $this->request('2026-06-02 10:00', practitioner: $other))))->toBe(BookingRuleViolation::PRACTITIONER_CANNOT_PERFORM);

        $this->asOwner(fn () => $this->practitioner->forceFill(['status' => 'inactive'])->save());

        expect(violation(fn () => $this->core->create($this->org, $this->request('2026-06-02 10:00'))))->toBe(BookingRuleViolation::PRACTITIONER_CANNOT_PERFORM);
    });

    it('refuse un créneau hors horaires avec les 3 prochains créneaux', function (): void {
        try {
            $this->core->create($this->org, $this->request('2026-06-02 20:00'));
            $this->fail('Une exception était attendue.');
        } catch (SlotUnavailable $e) {
            expect($e->reason)->toBe('outside_hours')->and($e->suggestions)->toHaveCount(3);
        }
    });

    it('applique le délai minimum aux clients mais pas au personnel', function (): void {
        // Il est 10:00 à Bruxelles : 11:00 est dans le délai de 2 h.
        expect(fn () => $this->core->create($this->org, $this->request('2026-06-01 11:00', enforcePolicies: true)))->toThrow(SlotUnavailable::class);

        expect($this->core->create($this->org, $this->request('2026-06-01 11:00', enforcePolicies: false))->status)->toBe('confirmed');
    });

    it('refuse un créneau déjà pris (E10) et propose les suivants', function (): void {
        $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        try {
            $this->core->create($this->org, $this->request('2026-06-02 10:15', key: 'autre'));
            $this->fail('Une exception était attendue.');
        } catch (SlotUnavailable $e) {
            expect($e->reason)->toBe('taken')->and($e->suggestions)->toHaveCount(3)
                ->and($e->suggestions[0]->startsAt->greaterThan(now()))->toBeTrue();
        }

        expect(Booking::query()->count())->toBe(1);
    });

    it('accepte deux rendez-vous bout à bout', function (): void {
        $this->core->create($this->org, $this->request('2026-06-02 10:00'));
        $second = $this->core->create($this->org, $this->request('2026-06-02 10:30', key: 'k2'));

        expect($second->status)->toBe('confirmed');
    });

    it('libère un blocage expiré pour un autre client', function (): void {
        $this->core->create($this->org, $this->request('2026-06-02 10:00', hold: true));
        $this->travel(11)->minutes();
        $other = $this->asOwner(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => '+32470999888']));

        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00', customer: $other));

        expect($booking->status)->toBe('confirmed')->and(Booking::query()->where('status', 'expired')->count())->toBe(1);
    });
});

describe('contrainte de la base (dernier garde-fou)', function (): void {
    it('empêche le double-booking même en contournant le cœur', function (): void {
        $first = $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        $row = collect($first->getAttributes())->except(['id'])->all();
        $row['idempotency_key'] = null;
        $row['starts_at'] = $first->starts_at->addMinutes(15);
        $row['ends_at'] = $first->ends_at->addMinutes(15);
        $row['blocked_from'] = $first->blocked_from->addMinutes(15);
        $row['blocked_until'] = $first->blocked_until->addMinutes(15);
        $row['notes_enc'] = null;

        try {
            DB::table('bookings')->insert($row);
            $this->fail('La base aurait dû refuser le chevauchement.');
        } catch (QueryException $e) {
            expect($e->errorInfo[0])->toBe('23P01');
        }
    });

    it('n\'occupe pas le créneau avec une réservation annulée', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));
        $this->core->cancel($this->org, $booking, $this->admin);

        expect($this->core->create($this->org, $this->request('2026-06-02 10:00', key: 'again'))->status)->toBe('confirmed');
    });
});

describe('confirmation d\'un blocage', function (): void {
    it('confirme avant l\'échéance', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00', hold: true));

        $this->core->confirm($booking, Actor::customer($this->customer->id));

        expect($booking->fresh()->status)->toBe('confirmed')->and($booking->fresh()->expires_at)->toBeNull();
    });

    it('refuse de confirmer après l\'échéance et libère le créneau', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00', hold: true));
        $this->travel(11)->minutes();

        expect(violation(fn () => $this->core->confirm($booking, Actor::customer(null))))->toBe(BookingRuleViolation::HOLD_EXPIRED)
            ->and($booking->fresh()->status)->toBe('expired');
    });

    it('refuse de confirmer une réservation qui ne l\'est pas en attente', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        expect(violation(fn () => $this->core->confirm($booking, $this->admin)))->toBe(BookingRuleViolation::INVALID_TRANSITION);
    });
});

describe('annulation', function (): void {
    it('permet au client d\'annuler hors de la fenêtre de 24 h', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-03 10:00'));

        $this->core->cancel($this->org, $booking, Actor::customer($this->customer->id), 'Empêchement');

        expect($booking->fresh()->status)->toBe('cancelled_by_customer')->and($booking->fresh()->cancellation_reason)->toBe('Empêchement')->and($booking->fresh()->cancelled_at)->not->toBeNull();
    });

    it('interdit au client d\'annuler dans la fenêtre, mais pas au commerce', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 09:00'));

        expect(violation(fn () => $this->core->cancel($this->org, $booking, Actor::customer(null))))->toBe(BookingRuleViolation::CANCELLATION_TOO_LATE);

        $this->core->cancel($this->org, $booking, $this->admin);

        expect($booking->fresh()->status)->toBe('cancelled_by_business');
    });

    it('refuse d\'annuler deux fois', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));
        $this->core->cancel($this->org, $booking, $this->admin);

        expect(violation(fn () => $this->core->cancel($this->org, $booking, $this->admin)))->toBe(BookingRuleViolation::INVALID_TRANSITION);
    });
});

describe('déplacement', function (): void {
    it('déplace un rendez-vous et journalise l\'ancien et le nouvel horaire', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-03 10:00'));

        $this->core->move($this->org, $booking, $this->brussels('2026-06-04 15:00'), $this->admin);

        $event = BookingEvent::query()->where('action', 'moved')->sole();

        expect($booking->fresh()->starts_at->equalTo($this->brussels('2026-06-04 15:00')))->toBeTrue()
            ->and($event->diff['from']['starts_at'])->toContain('2026-06-03T08:00:00')
            ->and($event->diff['to']['starts_at'])->toContain('2026-06-04T13:00:00');
    });

    it('permet de décaler un rendez-vous de quelques minutes sur son propre créneau', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-03 10:00'));

        $this->core->move($this->org, $booking, $this->brussels('2026-06-03 10:15'), $this->admin);

        expect($booking->fresh()->starts_at->equalTo($this->brussels('2026-06-03 10:15')))->toBeTrue();
    });

    it('refuse un créneau occupé et laisse le rendez-vous en place', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-03 10:00'));
        $this->core->create($this->org, $this->request('2026-06-03 14:00', key: 'k2'));

        expect(fn () => $this->core->move($this->org, $booking, $this->brussels('2026-06-03 14:15'), $this->admin))->toThrow(SlotUnavailable::class);

        expect($booking->fresh()->starts_at->equalTo($this->brussels('2026-06-03 10:00')))->toBeTrue();
    });

    it('refuse de déplacer une réservation annulée', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-03 10:00'));
        $this->core->cancel($this->org, $booking, $this->admin);

        expect(violation(fn () => $this->core->move($this->org, $booking, $this->brussels('2026-06-03 11:00'), $this->admin)))->toBe(BookingRuleViolation::INVALID_TRANSITION);
    });
});

describe('après le rendez-vous', function (): void {
    it('marque un rendez-vous passé comme honoré ou comme absence', function (string $method, string $status): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-01 11:00'));
        $this->travel(3)->hours();

        $this->core->{$method}($booking, $this->admin);

        expect($booking->fresh()->status)->toBe($status);
    })->with([['markCompleted', 'completed'], ['markNoShow', 'no_show']]);

    it('refuse de le faire avant l\'heure du rendez-vous', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        expect(violation(fn () => $this->core->markNoShow($booking, $this->admin)))->toBe(BookingRuleViolation::INVALID_TRANSITION);
    });
});

describe('paiement sur place', function (): void {
    it('enregistre un paiement complet et partiel', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        $this->core->recordPayment($booking, 'partially_paid', 1000, 'cash', $this->admin);
        expect($booking->fresh()->amount_collected_cents)->toBe(1000);

        $this->core->recordPayment($booking, 'paid_onsite', null, 'card', $this->admin);
        expect($booking->fresh()->amount_collected_cents)->toBe(2500)->and($booking->fresh()->payment_status)->toBe('paid_onsite');
    });

    it('refuse un montant supérieur au prix, un statut inconnu et un paiement partiel incohérent', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        expect(violation(fn () => $this->core->recordPayment($booking, 'paid_onsite', 9999, null, $this->admin)))->toBe(BookingRuleViolation::AMOUNT_EXCEEDS_PRICE)
            ->and(violation(fn () => $this->core->recordPayment($booking, 'partially_paid', 2500, null, $this->admin)))->toBe(BookingRuleViolation::AMOUNT_EXCEEDS_PRICE)
            ->and(violation(fn () => $this->core->recordPayment($booking, 'bitcoin', 1, null, $this->admin)))->toBe(BookingRuleViolation::INVALID_PAYMENT_STATUS);
    });

    it('refuse un paiement sur une réservation annulée', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));
        $this->core->cancel($this->org, $booking, $this->admin);

        expect(violation(fn () => $this->core->recordPayment($booking, 'paid_onsite', null, null, $this->admin)))->toBe(BookingRuleViolation::INVALID_TRANSITION);
    });
});

describe('notes', function (): void {
    it('ne copie jamais le contenu des notes dans le journal', function (): void {
        $booking = $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        $this->core->updateNotes($booking, 'Information médicale sensible', $this->admin);

        $event = BookingEvent::query()->where('action', 'notes_updated')->sole();

        expect($event->diff)->toBe(['length' => 29])->and(json_encode($event->diff))->not->toContain('médicale');
    });
});

describe('libération des blocages', function (): void {
    it('libère uniquement les blocages expirés', function (): void {
        $held = $this->core->create($this->org, $this->request('2026-06-02 10:00', hold: true));
        $this->travel(5)->minutes();
        $later = $this->core->create($this->org, $this->request('2026-06-02 11:00', hold: true, key: 'k2'));
        $this->travel(6)->minutes();

        expect($this->core->releaseExpiredHolds())->toBe(1)
            ->and($held->fresh()->status)->toBe('expired')
            ->and($later->fresh()->status)->toBe('pending')
            ->and(BookingEvent::query()->where('action', 'expired')->where('actor_type', 'system')->count())->toBe(1);
    });

    it('est exécutée par la commande planifiée pour toutes les organisations', function (): void {
        $held = $this->core->create($this->org, $this->request('2026-06-02 10:00', hold: true));
        $this->travel(11)->minutes();
        app(TenantContext::class)->clear();

        $this->artisan('bookings:release-holds')->expectsOutputToContain('1 créneau(x) libéré(s).')->assertSuccessful();

        app(TenantContext::class)->set($this->org->id);

        expect($held->fresh()->status)->toBe('expired');
    });
});

describe('journal en ajout seul', function (): void {
    it('refuse la modification et la suppression des événements', function (): void {
        $this->core->create($this->org, $this->request('2026-06-02 10:00'));

        expect(fn () => DB::table('booking_events')->update(['action' => 'x']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('booking_events')->delete())->toThrow(QueryException::class);
    });
});

describe('isolation entre organisations', function (): void {
    it('cache les réservations et clients d\'une autre organisation', function (): void {
        $this->core->create($this->org, $this->request('2026-06-02 10:00'));
        $other = $this->asOwner(fn () => Organization::factory()->create());

        app(TenantContext::class)->run($other->id, function (): void {
            expect(Booking::query()->count())->toBe(0)->and(BookingEvent::query()->count())->toBe(0)->and(Customer::query()->count())->toBe(0);
        });
    });

    it('refuse d\'écrire une réservation pour une autre organisation', function (): void {
        $other = $this->asOwner(fn () => Organization::factory()->create());

        expect(fn () => Booking::query()->create([
            'org_id' => $other->id, 'location_id' => $this->location->id, 'customer_id' => $this->customer->id, 'practitioner_id' => $this->practitioner->id, 'service_id' => $this->service->id,
            'starts_at' => now(), 'ends_at' => now()->addHour(), 'blocked_from' => now(), 'blocked_until' => now()->addHour(),
            'status' => 'confirmed', 'source' => 'dashboard', 'price_cents' => 0, 'vat_rate' => 0,
        ]))->toThrow(QueryException::class);
    });
});
