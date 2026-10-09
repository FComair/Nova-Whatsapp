<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Models\BookingTip;
use App\Modules\Booking\Models\BookingTipAllocation;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Identity\Models\User;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Pourboires : un montant séparé du prix et du montant encaissé, attribué à un ou plusieurs praticiens, journalisé.
 * Lundi 1er juin 2026, 08:00 UTC ; un rendez-vous de 25 € chez Léa le 2 juin.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld('2026-06-01 08:00:00', $org);
    app(TenantContext::class)->clear();

    $this->in = fn (callable $callback): mixed => app(TenantContext::class)->run($this->org->id, $callback);
    $this->as = fn (?User $user = null) => $this->signedIn($user ?? $this->owner, $this->org);

    $this->booking = fn (string $local = '2026-06-02 10:00', ?Practitioner $practitioner = null): Booking => ($this->in)(fn () => app(BookingCore::class)->create($this->org, $this->request($local, practitioner: $practitioner)));

    $this->marc = fn (string $name = 'Marc', string $status = Practitioner::STATUS_ACTIVE): Practitioner => $this->asOwner(function () use ($name, $status): Practitioner {
        $practitioner = Practitioner::query()->create(['org_id' => $this->org->id, 'name' => $name, 'color' => '#336699', 'status' => $status]);
        DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'service_id' => $this->service->id]);
        PractitionerSchedule::query()->create(['org_id' => $this->org->id, 'practitioner_id' => $practitioner->id, 'location_id' => $this->location->id, 'weekday' => 2, 'starts_at' => '09:00', 'ends_at' => '17:00']);

        return $practitioner;
    });

    $this->member = fn (string $role, ?Practitioner $practitioner = null): User => $this->asOwner(function () use ($role, $practitioner): User {
        $user = User::factory()->create(['email' => "{$role}@example.com"]);
        OrgMember::factory()->create(['org_id' => $this->org->id, 'user_id' => $user->id, 'role' => $role]);
        $practitioner?->forceFill(['user_id' => $user->id])->save();

        return $user;
    });

    // Enregistre un paiement (avec ou sans pourboire) comme le fait le formulaire.
    $this->pay = fn (Booking $booking, array $fields = [], ?User $user = null) => ($this->as)($user)->put("/planning/rendez-vous/{$booking->id}/paiement", ['payment_status' => 'paid_onsite', 'method' => 'card', ...$fields]);
    $this->tipOf = fn (Booking $booking): ?BookingTip => ($this->in)(fn () => BookingTip::query()->with('allocations')->where('booking_id', $booking->id)->first());
    $this->fresh = fn (Booking $booking): Booking => ($this->in)(fn () => $booking->fresh());
    $this->tipAudit = fn (): array => ($this->in)(fn () => AuditLog::query()->where('action', 'like', 'booking.tip.%')->orderBy('created_at')->get()->all());
});

describe('enregistrement', function (): void {
    it('stocke le pourboire à part, sans toucher au prix ni au montant encaissé', function (): void {
        $booking = ($this->booking)();

        ($this->pay)($booking, ['amount' => '25', 'tip' => '5'])->assertSessionHasNoErrors();

        $tip = ($this->tipOf)($booking);
        $fresh = ($this->fresh)($booking);

        expect($tip->amount_cents)->toBe(500)
            ->and($tip->currency)->toBe('EUR')
            ->and($tip->customer_id)->toBe($this->customer->id)
            ->and($tip->recorded_by)->toBe($this->owner->id)
            ->and($tip->paid_at)->not->toBeNull()
            ->and($fresh->price_cents)->toBe(2500)
            ->and($fresh->amount_collected_cents)->toBe(2500)
            ->and($fresh->payment_status)->toBe('paid_onsite');
    });

    it('attribue le pourboire au praticien du rendez-vous par défaut', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5,50']);

        $tip = ($this->tipOf)($booking);

        expect($tip->amount_cents)->toBe(550)
            ->and($tip->allocations)->toHaveCount(1)
            ->and($tip->allocations[0]->practitioner_id)->toBe($this->practitioner->id)
            ->and($tip->allocations[0]->amount_cents)->toBe(550);
    });

    it('accepte un pourboire vide ou nul : aucun pourboire n\'est enregistré', function (): void {
        $booking = ($this->booking)();

        ($this->pay)($booking, ['tip' => ''])->assertSessionHasNoErrors();
        ($this->pay)($booking, ['tip' => '0'])->assertSessionHasNoErrors();
        ($this->pay)($booking, [])->assertSessionHasNoErrors();

        expect(($this->tipOf)($booking))->toBeNull()->and(($this->tipAudit)())->toBe([]);
    });

    it('supprime le pourboire quand on le vide ou le met à zéro, et journalise la suppression', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5']);
        ($this->pay)($booking, ['tip' => '']);

        expect(($this->tipOf)($booking))->toBeNull()
            ->and(array_map(fn ($row) => $row->action, ($this->tipAudit)()))->toBe(['booking.tip.added', 'booking.tip.removed']);
        expect(($this->in)(fn () => BookingTipAllocation::query()->count()))->toBe(0);
    });

    it('ne touche pas au pourboire quand la requête n\'en parle pas', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5']);
        ($this->pay)($booking, ['payment_status' => 'paid_onsite', 'method' => 'cash']);

        expect(($this->tipOf)($booking)->amount_cents)->toBe(500);
    });

    it('accepte un pourboire sur un service offert, pas sur un impayé ni une perte', function (): void {
        $waived = ($this->booking)('2026-06-02 10:00');
        $unpaid = ($this->booking)('2026-06-02 11:00');
        $lost = ($this->booking)('2026-06-02 12:00');

        ($this->pay)($waived, ['payment_status' => 'waived', 'tip' => '3'])->assertSessionHasNoErrors();
        ($this->pay)($unpaid, ['payment_status' => 'unpaid', 'tip' => '3'])->assertSessionHasErrors(['booking' => __('ui.planning.errors.tip_requires_payment', [], 'fr')]);
        ($this->pay)($lost, ['payment_status' => 'written_off', 'tip' => '3'])->assertSessionHasErrors('booking');

        expect(($this->tipOf)($waived)->amount_cents)->toBe(300)
            ->and(($this->tipOf)($unpaid))->toBeNull()
            ->and(($this->fresh)($unpaid)->payment_status)->toBe('unpaid'); // le paiement refusé n'est pas enregistré non plus
    });

    it('refuse un pourboire invalide ou négatif, et ne change rien', function (): void {
        $booking = ($this->booking)();

        foreach (['abc', '-5', '5.123', '1e3', '5,,5'] as $bad) {
            ($this->pay)($booking, ['amount' => '25', 'tip' => $bad])->assertSessionHasErrors('tip');
        }

        expect(($this->tipOf)($booking))->toBeNull()->and(($this->fresh)($booking)->payment_status)->toBe('unpaid');
    });

    it('lit les montants avec virgule ou point', function (): void {
        $a = ($this->booking)('2026-06-02 10:00');
        $b = ($this->booking)('2026-06-02 11:00');
        ($this->pay)($a, ['tip' => '2,5']);
        ($this->pay)($b, ['tip' => '2.05']);

        expect(($this->tipOf)($a)->amount_cents)->toBe(250)->and(($this->tipOf)($b)->amount_cents)->toBe(205);
    });

    it('refuse un pourboire sur un rendez-vous annulé', function (): void {
        $booking = ($this->booking)();
        ($this->in)(fn () => Booking::query()->whereKey($booking->id)->update(['status' => 'cancelled_by_customer']));

        ($this->pay)($booking, ['tip' => '5'])->assertSessionHasErrors('booking');
        expect(($this->tipOf)($booking))->toBeNull();
    });
});

describe('un seul pourboire par rendez-vous', function (): void {
    it('modifie le pourboire existant au lieu d\'en créer un second', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5']);
        ($this->pay)($booking, ['tip' => '8']);

        expect(($this->in)(fn () => BookingTip::query()->count()))->toBe(1)
            ->and(($this->tipOf)($booking)->amount_cents)->toBe(800)
            ->and(($this->in)(fn () => BookingTipAllocation::query()->count()))->toBe(1);
    });

    it('est garanti par la base : deux pourboires pour un même rendez-vous sont impossibles', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5']);

        $insert = fn () => ($this->in)(fn () => DB::table('booking_tips')->insert(['org_id' => $this->org->id, 'booking_id' => $booking->id, 'customer_id' => $this->customer->id, 'amount_cents' => 100, 'currency' => 'EUR', 'paid_at' => now()]));

        expect($insert)->toThrow(QueryException::class);
    });
});

describe('répartition entre praticiens', function (): void {
    it('répartit le pourboire entre plusieurs praticiens', function (): void {
        $marc = ($this->marc)();
        $booking = ($this->booking)();

        ($this->pay)($booking, ['tip' => '10', 'tip_allocations' => [
            ['practitioner_id' => $this->practitioner->id, 'amount' => '6'],
            ['practitioner_id' => $marc->id, 'amount' => '4'],
        ]])->assertSessionHasNoErrors();

        $tip = ($this->tipOf)($booking);

        expect($tip->amount_cents)->toBe(1000)
            ->and($tip->allocations->pluck('amount_cents', 'practitioner_id')->all())->toBe([$this->practitioner->id => 600, $marc->id => 400]);
    });

    it('refuse des parts qui ne totalisent pas le pourboire', function (): void {
        $marc = ($this->marc)();
        $booking = ($this->booking)();

        ($this->pay)($booking, ['tip' => '10', 'tip_allocations' => [
            ['practitioner_id' => $this->practitioner->id, 'amount' => '6'],
            ['practitioner_id' => $marc->id, 'amount' => '3'],
        ]])->assertSessionHasErrors(['booking' => __('ui.planning.errors.tip_allocation_mismatch', [], 'fr')]);

        expect(($this->tipOf)($booking))->toBeNull()->and(($this->fresh)($booking)->payment_status)->toBe('unpaid');
    });

    it('refuse un bénéficiaire en double, inactif, inconnu ou une part nulle', function (): void {
        $marc = ($this->marc)();
        $away = ($this->marc)('Ancien', 'inactive');
        $booking = ($this->booking)();
        $part = fn (string $id, string $amount) => ['practitioner_id' => $id, 'amount' => $amount];

        $cases = [
            'double' => [$part($marc->id, '5'), $part($marc->id, '5')],
            'inactif' => [$part($this->practitioner->id, '5'), $part($away->id, '5')],
            'inconnu' => [$part($this->practitioner->id, '5'), $part('00000000-0000-4000-8000-000000000000', '5')],
        ];

        foreach ($cases as $allocations) {
            ($this->pay)($booking, ['tip' => '10', 'tip_allocations' => $allocations])->assertSessionHasErrors(['booking' => __('ui.planning.errors.tip_recipient_invalid', [], 'fr')]);
        }

        ($this->pay)($booking, ['tip' => '5', 'tip_allocations' => [$part($this->practitioner->id, '5'), $part($marc->id, '0')]])->assertSessionHasErrors('booking');
        expect(($this->tipOf)($booking))->toBeNull();
    });

    it('exige de choisir le bénéficiaire quand aucun praticien actif n\'est attribué', function (): void {
        $marc = ($this->marc)();
        $booking = ($this->booking)();
        $this->asOwner(fn () => Practitioner::query()->whereKey($this->practitioner->id)->update(['status' => 'inactive']));

        ($this->pay)($booking, ['tip' => '5'])->assertSessionHasErrors(['booking' => __('ui.planning.errors.tip_recipient_required', [], 'fr')]);
        expect(($this->tipOf)($booking))->toBeNull();

        ($this->pay)($booking, ['tip' => '5', 'tip_allocations' => [['practitioner_id' => $marc->id, 'amount' => '5']]])->assertSessionHasNoErrors();
        expect(($this->tipOf)($booking)->allocations[0]->practitioner_id)->toBe($marc->id);
    });

    it('garantit par la base que les parts totalisent le pourboire', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5']);
        $tip = ($this->tipOf)($booking);

        $break = fn () => ($this->in)(function () use ($tip): void {
            DB::transaction(function () use ($tip): void {
                DB::table('booking_tip_allocations')->where('tip_id', $tip->id)->update(['amount_cents' => 100]);
                DB::statement('SET CONSTRAINTS booking_tip_allocations_sum IMMEDIATE');
            });
        });

        expect($break)->toThrow(QueryException::class, 'ne totalisent pas');
        expect(($this->tipOf)($booking)->allocations[0]->amount_cents)->toBe(500);
    });
});

describe('journal des modifications', function (): void {
    it('trace l\'ajout avec qui, quand, la référence et les valeurs', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5']);

        [$entry] = ($this->tipAudit)();
        $diff = $entry->diff;

        expect($entry->action)->toBe('booking.tip.added')
            ->and($entry->actor_id)->toBe($this->owner->id)
            ->and($entry->entity_id)->toBe($booking->id)
            ->and($entry->org_id)->toBe($this->org->id)
            ->and($entry->created_at)->not->toBeNull()
            ->and($diff['from'])->toBeNull()
            ->and($diff['to']['amount_cents'])->toBe(500)
            ->and($diff['to']['allocations'][0]['practitioner_id'])->toBe($this->practitioner->id)
            ->and($diff['currency'])->toBe('EUR');
    });

    it('trace une modification et une nouvelle répartition avec l\'avant et l\'après', function (): void {
        $marc = ($this->marc)();
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5']);
        ($this->pay)($booking, ['tip' => '8']);
        ($this->pay)($booking, ['tip' => '8', 'tip_allocations' => [['practitioner_id' => $this->practitioner->id, 'amount' => '5'], ['practitioner_id' => $marc->id, 'amount' => '3']]]);

        $entries = ($this->tipAudit)();

        expect(array_map(fn ($row) => $row->action, $entries))->toBe(['booking.tip.added', 'booking.tip.changed', 'booking.tip.reallocated']);
        expect($entries[1]->diff['from']['amount_cents'])->toBe(500)->and($entries[1]->diff['to']['amount_cents'])->toBe(800);
        expect($entries[2]->diff['from']['allocations'])->toHaveCount(1)->and($entries[2]->diff['to']['allocations'])->toHaveCount(2);
    });

    it('ne journalise rien quand rien ne change', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5']);
        ($this->pay)($booking, ['tip' => '5']);

        expect(($this->tipAudit)())->toHaveCount(1);
    });

    it('ajoute l\'événement à l\'historique du rendez-vous', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '5']);

        $events = ($this->in)(fn () => BookingEvent::query()->where('booking_id', $booking->id)->where('action', 'tip_updated')->get());

        expect($events)->toHaveCount(1)->and($events[0]->diff['action'])->toBe('added')->and($events[0]->diff['to']['amount_cents'])->toBe(500);
    });
});

describe('droits et affichage', function (): void {
    it('suit les droits du paiement : réceptionniste et responsable oui, praticien seulement les siens', function (): void {
        $marc = ($this->marc)();
        $mine = ($this->booking)('2026-06-02 10:00', $marc);
        $theirs = ($this->booking)('2026-06-02 11:00');
        $user = ($this->member)('practitioner', $marc);

        ($this->pay)($mine, ['tip' => '2'], $user)->assertSessionHasNoErrors();
        ($this->pay)($theirs, ['tip' => '2'], $user)->assertForbidden();
        ($this->pay)($theirs, ['tip' => '2'], ($this->member)('receptionist'))->assertSessionHasNoErrors();
        ($this->pay)(($this->booking)('2026-06-02 12:00'), ['tip' => '2'], ($this->member)('site_manager'))->assertSessionHasNoErrors();

        expect(($this->tipOf)($mine)->amount_cents)->toBe(200)->and(($this->tipOf)($theirs)->amount_cents)->toBe(200);
    });

    it('expose le pourboire et ses bénéficiaires, séparés du prix et de l\'encaissé, dans le planning', function (): void {
        $marc = ($this->marc)();
        $booking = ($this->booking)();
        ($this->pay)($booking, ['amount' => '25', 'tip' => '10', 'tip_allocations' => [['practitioner_id' => $this->practitioner->id, 'amount' => '7'], ['practitioner_id' => $marc->id, 'amount' => '3']]]);

        $props = Assert::fromTestResponse(($this->as)()->get('/planning?date=2026-06-02')->assertOk())->toArray()['props'];
        $row = collect($props['week']['days'])->flatMap(fn ($day) => $day['bookings'] ?? [])->firstWhere('id', $booking->id) ?? collect($props['bookings'] ?? [])->firstWhere('id', $booking->id);

        expect($row['price_cents'])->toBe(2500)
            ->and($row['amount_collected_cents'])->toBe(2500)
            ->and($row['tip']['amount_cents'])->toBe(1000)
            ->and(collect($row['tip']['allocations'])->pluck('amount_cents', 'practitioner_name')->all())->toBe(['Léa' => 700, 'Marc' => 300]);
    });

    it('n\'expose aucun pourboire quand il n\'y en a pas', function (): void {
        $booking = ($this->booking)();
        ($this->pay)($booking, ['tip' => '']);

        $props = Assert::fromTestResponse(($this->as)()->get('/planning?date=2026-06-02')->assertOk())->toArray()['props'];
        $row = collect($props['bookings'] ?? [])->firstWhere('id', $booking->id);

        expect($row['tip'])->toBeNull();
    });
});
