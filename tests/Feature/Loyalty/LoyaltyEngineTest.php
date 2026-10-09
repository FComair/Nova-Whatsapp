<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Support\Actor;
use App\Modules\Booking\Support\NewBooking;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Models\LoyaltyMember;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Models\LoyaltyReward;
use App\Modules\Loyalty\Services\LoyaltyEngine;
use App\Modules\Loyalty\Services\LoyaltyPrograms;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuthScenarios;
use Tests\Support\BookingScenarios;

uses(AuthScenarios::class, BookingScenarios::class);

/**
 * Programme de fidélité : gagner des tampons (jamais deux fois, jamais pour un rendez-vous annulé, absent ou non éligible),
 * ajustements manuels, récompenses attribuées avec leur instantané, solde toujours égal au journal.
 * « Maintenant » = lundi 1er juin 2026 ; les visites sont créées pour le 2 juin puis terminées le 5 juin.
 */
beforeEach(function (): void {
    ['user' => $this->owner, 'org' => $org] = $this->activatedAccount();
    $this->bookingWorld('2026-06-01 08:00:00', $org);

    $this->in = fn (callable $callback): mixed => app(TenantContext::class)->run($this->org->id, $callback);
    $this->engine = fn (): LoyaltyEngine => app(LoyaltyEngine::class);

    // Programme actif par défaut : 3 visites donnent une récompense (petit nombre pour garder les tests courts).
    $this->program = fn (array $override = [], bool $activate = true): LoyaltyProgram => ($this->in)(function () use ($override, $activate): LoyaltyProgram {
        $programs = app(LoyaltyPrograms::class);
        $programs->save([
            ...LoyaltyPrograms::defaults(),
            'name' => ['fr' => 'Fidélité', 'nl' => '', 'en' => 'Loyalty'],
            'reward_description' => ['fr' => 'Une coupe offerte', 'nl' => '', 'en' => 'A free haircut'],
            'terms' => ['fr' => 'Non cumulable.', 'nl' => '', 'en' => ''],
            'required_visits' => 3,
            ...$override,
        ], $this->owner->id, true);

        if ($activate) {
            $programs->activate($this->owner->id);
        }

        return LoyaltyProgram::query()->firstOrFail();
    });

    $this->slot = 0;
    // Un rendez-vous confirmé pour le 2 juin, à une heure différente à chaque appel.
    $this->book = function (?Customer $customer = null, ?Service $service = null) {
        $time = sprintf('2026-06-02 %02d:%02d', 9 + intdiv($this->slot, 2), ($this->slot % 2) * 30);
        $this->slot++;
        $request = $this->request($time, customer: $customer);

        return ($this->in)(fn () => app(BookingCore::class)->create($this->org, $service === null ? $request : new NewBooking(
            $request->locationId, $service->id, $request->practitionerId, $request->customerId, $request->startsAt, $request->source, $request->actor, enforcePolicies: false,
        )));
    };
    $this->finishWith = function (Booking $booking, string $outcome = 'completed') {
        $this->travelTo(TenantContext::class === '' ? now() : CarbonImmutable::parse('2026-06-05 12:00:00', 'UTC'));

        return ($this->in)(fn () => $outcome === 'completed' ? app(BookingCore::class)->markCompleted($booking, Actor::user(null)) : app(BookingCore::class)->markNoShow($booking, Actor::user(null)));
    };
    // Un rendez-vous terminé en une fois.
    $this->visit = fn (?Customer $customer = null, ?Service $service = null): Booking => ($this->finishWith)(($this->book)($customer, $service));
    $this->member = fn (?Customer $customer = null): ?LoyaltyMember => ($this->in)(fn () => LoyaltyMember::query()->where('customer_id', ($customer ?? $this->customer)->id)->first());
    $this->ledger = fn (?string $kind = null): array => ($this->in)(fn () => LoyaltyLedgerEntry::query()->whereNotNull('customer_id')->when($kind, fn ($q) => $q->where('kind', $kind))->orderBy('id')->get()->all());
    $this->rewards = fn (?string $status = null): array => ($this->in)(fn () => LoyaltyReward::query()->when($status, fn ($q) => $q->where('status', $status))->get()->all());
    $this->ledgerSum = fn (?Customer $customer = null): int => (int) ($this->in)(fn () => LoyaltyLedgerEntry::query()->where('customer_id', ($customer ?? $this->customer)->id)->sum('stamps_delta'));
});

describe('gagner des tampons', function (): void {
    it('gagne un tampon à la fin d\'un rendez-vous éligible et inscrit le client', function (): void {
        ($this->program)();

        $booking = ($this->visit)();
        $member = ($this->member)();

        expect($member)->not->toBeNull()->and($member->stamps)->toBe(1)->and($member->lifetime_stamps)->toBe(1)->and($member->status)->toBe('active')
            ->and($member->joined_via)->toBe('auto')->and($member->last_visit_at)->not->toBeNull()
            ->and(collect(($this->ledger)())->pluck('kind')->all())->toBe(['enrolled', 'stamp_earned'])
            ->and(($this->ledger)('stamp_earned')[0]->booking_id)->toBe($booking->id);
    });

    it('ne donne rien pour un client absent, ni tant que le rendez-vous n\'est pas terminé', function (): void {
        ($this->program)();

        ($this->finishWith)(($this->book)(), 'no_show');
        ($this->book)();

        expect(($this->member)())->toBeNull()->and(($this->ledger)())->toBe([]);
    });

    it('ne donne rien pour un rendez-vous annulé', function (): void {
        ($this->program)();
        $booking = ($this->book)();
        ($this->in)(fn () => app(BookingCore::class)->cancel($this->org, $booking, Actor::user(null)));

        expect(($this->member)())->toBeNull()->and(($this->ledger)('stamp_earned'))->toBe([]);
    });

    it('ne donne rien tant que le programme est brouillon, en pause ou archivé', function (string $state): void {
        ($this->program)([], $state !== 'draft');
        $this->in = $this->in;

        if ($state === 'paused') {
            ($this->in)(fn () => app(LoyaltyPrograms::class)->pause($this->owner->id));
        }

        if ($state === 'archived') {
            ($this->in)(fn () => app(LoyaltyPrograms::class)->archive($this->owner->id));
        }

        ($this->visit)();

        expect(($this->member)())->toBeNull();
    })->with(['draft', 'paused', 'archived']);

    it('ne compte jamais deux fois le même rendez-vous, même si l\'événement est rejoué', function (): void {
        ($this->program)();
        $booking = ($this->visit)();

        ($this->in)(function () use ($booking): void {
            app(LoyaltyEngine::class)->earn($booking);
            app(LoyaltyEngine::class)->onBookingChanged($booking);
        });

        expect(($this->member)()->stamps)->toBe(1)->and(($this->ledger)('stamp_earned'))->toHaveCount(1);
    });

    it('la base empêche aussi le doublon (index unique par rendez-vous et programme)', function (): void {
        $program = ($this->program)();
        $booking = ($this->visit)();

        expect(fn () => ($this->in)(fn () => app(LoyaltyEngine::class)->record($program, $this->customer->id, 'stamp_earned', 1, 2, bookingId: $booking->id)))->toThrow(QueryException::class);
    });

    it('ne compte que les services choisis', function (): void {
        $other = $this->asOwner(function (): Service {
            $service = Service::query()->create(['org_id' => $this->org->id, 'name' => 'Couleur', 'duration_minutes' => 30, 'price_cents' => 6000]);
            DB::table('practitioner_services')->insert(['org_id' => $this->org->id, 'practitioner_id' => $this->practitioner->id, 'service_id' => $service->id]);

            return $service;
        });
        ($this->program)(['services_mode' => 'selected', 'earn_services' => [$this->service->id]]);

        ($this->visit)(null, $other);
        expect(($this->member)())->toBeNull();

        ($this->visit)();
        expect(($this->member)()->stamps)->toBe(1);
    });

    it('respecte le montant minimum', function (): void {
        ($this->program)(['min_amount_cents' => 3000]);

        ($this->visit)(); // 25 €

        expect(($this->member)())->toBeNull();
    });

    it('avec « paiement exigé », ne compte qu\'un rendez-vous payé, et retire le tampon si le paiement est annulé', function (): void {
        ($this->program)(['require_payment' => true]);
        $booking = ($this->visit)();

        expect(($this->member)())->toBeNull();

        $pay = fn (string $status) => ($this->in)(fn () => app(BookingCore::class)->recordPayment($booking, $status, null, 'card', Actor::user(null)));
        $pay('paid_onsite');
        expect(($this->member)()->stamps)->toBe(1);

        $pay('unpaid');
        expect(($this->member)()->stamps)->toBe(0)->and(($this->ledger)('stamp_reversed'))->toHaveCount(1)->and(($this->ledgerSum)())->toBe(0);

        // Payé à nouveau : le rendez-vous ne regagne pas de tampon (l'équipe peut en ajouter un à la main, avec un motif).
        $pay('paid_onsite');
        expect(($this->member)()->stamps)->toBe(0);
    });

    it('« offert » ne vaut pas paiement quand le paiement est exigé', function (): void {
        ($this->program)(['require_payment' => true]);
        $booking = ($this->visit)();

        ($this->in)(fn () => app(BookingCore::class)->recordPayment($booking, 'waived', null, null, Actor::user(null)));

        expect(($this->member)())->toBeNull();
    });

    it('sans paiement exigé, un paiement annulé ne retire rien', function (): void {
        ($this->program)();
        $booking = ($this->visit)();

        ($this->in)(fn () => app(BookingCore::class)->recordPayment($booking, 'unpaid', null, null, Actor::user(null)));

        expect(($this->member)()->stamps)->toBe(1)->and(($this->ledger)('stamp_reversed'))->toBe([]);
    });

    it('sans inscription automatique, seuls les clients inscrits par le personnel avancent', function (): void {
        ($this->program)(['auto_enroll' => false]);

        ($this->visit)();
        expect(($this->member)())->toBeNull();

        ($this->in)(fn () => app(LoyaltyEngine::class)->enroll($this->customer, $this->owner->id));
        ($this->visit)();

        expect(($this->member)()->stamps)->toBe(1)->and(($this->member)()->joined_via)->toBe('staff');
    });

    it('un client qui quitte le programme n\'avance plus mais garde ses tampons, et peut revenir', function (): void {
        ($this->program)();
        ($this->visit)();

        ($this->in)(fn () => app(LoyaltyEngine::class)->optOut($this->customer, $this->owner->id, 'À sa demande'));
        ($this->visit)();
        expect(($this->member)()->stamps)->toBe(1)->and(($this->member)()->status)->toBe('opted_out');

        ($this->in)(fn () => app(LoyaltyEngine::class)->rejoin($this->customer, $this->owner->id));
        ($this->visit)();
        expect(($this->member)()->stamps)->toBe(2)->and(collect(($this->ledger)())->pluck('kind')->contains('opted_out'))->toBeTrue();
    });

    it('un client effacé quitte le programme et perd ses récompenses disponibles', function (): void {
        ($this->program)(['required_visits' => 1]);
        ($this->visit)();

        ($this->in)(fn () => app(LoyaltyEngine::class)->customerErased($this->customer));

        expect(($this->member)()->status)->toBe('opted_out')->and(($this->rewards)('void'))->toHaveCount(1)->and(($this->rewards)('available'))->toBe([]);
    });
});

describe('récompenses', function (): void {
    it('attribue une récompense au bon nombre de visites, avec un instantané, et garde le surplus', function (): void {
        $program = ($this->program)(['expiry_days' => 30]);

        foreach (range(1, 4) as $_) {
            ($this->visit)();
        }

        $member = ($this->member)();
        [$reward] = ($this->rewards)();

        expect($member->stamps)->toBe(1)->and($member->rewards_earned)->toBe(1)->and($member->lifetime_stamps)->toBe(4)
            ->and($reward->status)->toBe('available')->and($reward->program_id)->toBe($program->id)
            ->and($reward->snapshot['description']['fr'])->toBe('Une coupe offerte')->and($reward->snapshot['required_visits'])->toBe(3)
            ->and($reward->expires_at->isSameDay(now()->addDays(30)))->toBeTrue()
            ->and(($this->ledgerSum)())->toBe($member->stamps)
            ->and(collect(($this->ledger)())->pluck('kind')->filter(fn ($k) => $k === 'reward_issued')->count())->toBe(1);
    });

    it('le solde reste toujours égal à la somme du journal', function (): void {
        ($this->program)();

        foreach (range(1, 7) as $_) {
            ($this->visit)();
        }

        ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, -1, 'Erreur de saisie', $this->owner->id));

        expect(($this->member)()->stamps)->toBe(($this->ledgerSum)())->and(($this->rewards)())->toHaveCount(2);
    });

    it('modifier le programme ne change pas une récompense déjà obtenue', function (): void {
        ($this->program)(['required_visits' => 1, 'reward_type' => 'percent_discount', 'reward_value' => 20]);
        ($this->visit)();

        ($this->in)(fn () => app(LoyaltyPrograms::class)->save([
            ...app(LoyaltyPrograms::class)->values(LoyaltyProgram::query()->firstOrFail()),
            'reward_type' => 'fixed_amount', 'reward_value' => 500, 'reward_description' => ['fr' => 'Cinq euros', 'nl' => '', 'en' => ''],
        ], $this->owner->id, true));

        [$reward] = ($this->rewards)();

        expect($reward->snapshot['type'])->toBe('percent_discount')->and($reward->snapshot['value'])->toBe(20)->and($reward->description('fr'))->toBe('Une coupe offerte');
    });

    it('un rendez-vous annulé après coup retire le tampon et annule la récompense qu\'il a permis (non utilisée)', function (): void {
        ($this->program)(['require_payment' => true, 'required_visits' => 2]);
        $first = ($this->visit)();
        $second = ($this->visit)();
        $pay = fn (Booking $booking, string $status) => ($this->in)(fn () => app(BookingCore::class)->recordPayment($booking, $status, null, 'card', Actor::user(null)));

        $pay($first, 'paid_onsite');
        $pay($second, 'paid_onsite');
        expect(($this->rewards)('available'))->toHaveCount(1)->and(($this->member)()->stamps)->toBe(0);

        $pay($second, 'unpaid');

        expect(($this->rewards)('void'))->toHaveCount(1)->and(($this->rewards)('available'))->toBe([])->and(($this->member)()->stamps)->toBe(1)->and(($this->ledgerSum)())->toBe(1);
    });
});

describe('ajustements manuels', function (): void {
    it('ajoute ou retire des tampons avec un motif, au journal et à l\'audit', function (): void {
        ($this->program)();

        $member = ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, 2, 'Visite faite avant le programme', $this->owner->id));
        expect($member->stamps)->toBe(2)->and($member->joined_via)->toBe('staff');

        $member = ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, -1, 'Erreur de saisie', $this->owner->id));
        $entries = ($this->ledger)('manual_adjustment');

        expect($member->stamps)->toBe(1)->and($entries)->toHaveCount(2)->and($entries[0]->reason)->toBe('Visite faite avant le programme')->and($entries[0]->actor_id)->toBe($this->owner->id)
            ->and($entries[1]->stamps_delta)->toBe(-1)->and($entries[1]->balance_after)->toBe(1)
            ->and(($this->in)(fn () => AuditLog::query()->where('action', 'loyalty.adjusted')->count()))->toBe(2)
            ->and(($this->ledgerSum)())->toBe(1);
    });

    it('exige un motif, un nombre raisonnable et un solde jamais négatif', function (int $delta, string $reason, string $code): void {
        ($this->program)();
        ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, 1, 'Départ', $this->owner->id));

        expect(fn () => ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, $delta, $reason, $this->owner->id)))->toThrow(InvalidArgumentException::class, $code)
            ->and(($this->member)()->stamps)->toBe(1);
    })->with([
        'sans motif' => [1, '  ', 'reason_required'],
        'zéro' => [0, 'Correction', 'invalid_amount'],
        'trop grand' => [51, 'Correction', 'invalid_amount'],
        'négatif' => [-2, 'Correction', 'negative_balance'],
    ]);

    it('attribue une récompense quand l\'ajout atteint le nombre de visites', function (): void {
        ($this->program)();

        ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, 3, 'Carte papier reprise', $this->owner->id));

        expect(($this->rewards)('available'))->toHaveCount(1)->and(($this->member)()->stamps)->toBe(0);
    });

    it('refuse tout ajustement sans programme en cours', function (): void {
        expect(fn () => ($this->in)(fn () => app(LoyaltyEngine::class)->adjust($this->customer, 1, 'Test', $this->owner->id)))->toThrow(InvalidArgumentException::class, 'no_program');
    });
});

describe('fiches en double', function (): void {
    it('transfère le solde et les récompenses disponibles vers la fiche conservée, sans réécrire le journal', function (): void {
        ($this->program)();
        $duplicate = $this->asOwner(fn () => Customer::query()->create(['org_id' => $this->org->id, 'phone_e164' => '+32470999888', 'name' => 'Doublon']));
        ($this->visit)($duplicate);
        ($this->visit)($duplicate);
        ($this->visit)();

        ($this->in)(fn () => app(LoyaltyEngine::class)->mergeCustomers($duplicate, $this->customer, $this->owner->id));

        // 2 tampons + 1 tampon = 3 : une récompense est attribuée à la fiche conservée.
        expect(($this->member)()->stamps)->toBe(0)->and(($this->rewards)('available'))->toHaveCount(1)->and(($this->member)($duplicate)->status)->toBe('opted_out')
            ->and(($this->member)($duplicate)->stamps)->toBe(0)->and(($this->ledgerSum)($duplicate))->toBe(0)->and(($this->ledgerSum)())->toBe(0);
    });
});

describe('journal et isolation', function (): void {
    it('est en ajout seul : aucune modification ni suppression par l\'application', function (): void {
        ($this->program)();
        ($this->visit)();

        expect(fn () => ($this->in)(fn () => DB::statement('update loyalty_ledger set stamps_delta = 5')))->toThrow(QueryException::class)
            ->and(fn () => ($this->in)(fn () => DB::statement('delete from loyalty_ledger')))->toThrow(QueryException::class);
    });

    it('isole les données entre établissements', function (): void {
        ($this->program)();
        ($this->visit)();
        ['org' => $otherOrg] = $this->activatedAccount(email: 'autre@example.com');

        $seen = app(TenantContext::class)->run($otherOrg->id, fn () => [
            DB::table('loyalty_programs')->count(), DB::table('loyalty_members')->count(), DB::table('loyalty_ledger')->count(), DB::table('loyalty_rewards')->count(),
        ]);

        expect($seen)->toBe([0, 0, 0, 0]);
    });

    it('un seul programme non archivé à la fois', function (): void {
        ($this->program)();

        expect(fn () => $this->asOwner(fn () => DB::table('loyalty_programs')->insert(['id' => (string) Str::uuid(), 'org_id' => $this->org->id, 'status' => 'draft'])))->toThrow(QueryException::class);

        ($this->in)(fn () => app(LoyaltyPrograms::class)->archive($this->owner->id));
        ($this->program)();

        expect(($this->in)(fn () => LoyaltyProgram::query()->count()))->toBe(2);
    });
});
