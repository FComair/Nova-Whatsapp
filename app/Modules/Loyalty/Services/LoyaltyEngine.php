<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Services;

use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Models\LoyaltyMember;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Models\LoyaltyReward;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Cœur du programme de fidélité (docs/27) : gagner, annuler et ajuster des tampons, attribuer les récompenses.
 *
 * Règles :
 * - un tampon seulement quand un rendez-vous éligible est TERMINÉ (jamais annulé, absent, en attente) pendant que le programme est
 *   ACTIF ; jamais deux fois pour le même rendez-vous (index unique + verrou de la ligne du client) ;
 * - si le programme exige un paiement, seul « payé sur place » compte ; un paiement ensuite annulé retire le tampon ;
 * - un client qui a quitté le programme n'avance plus ; sans inscription automatique, seuls les clients inscrits par le personnel avancent ;
 * - atteindre le nombre de visites attribue une récompense et retire ce nombre du solde : le surplus est conservé ;
 * - le solde (`loyalty_members.stamps`) égale toujours la somme du journal ;
 * - toute modification est une ligne du journal, jamais une mise à jour.
 */
final class LoyaltyEngine
{
    public function __construct(private readonly LoyaltyMessenger $messenger) {}

    /**
     * Un rendez-vous vient d'être terminé ou payé : gagne un tampon s'il est éligible. Sans effet sinon, et sans risque de doublon.
     * Ne lève jamais d'exception vers le cœur de réservation : un incident de fidélité ne doit pas bloquer un rendez-vous.
     */
    public function onBookingChanged(Booking $booking): void
    {
        try {
            $this->earn($booking);
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function earn(Booking $booking): ?LoyaltyLedgerEntry
    {
        $program = LoyaltyProgram::earning();

        if ($program === null || ! $this->eligible($program, $booking)) {
            return null;
        }

        $customer = Customer::query()->find($booking->customer_id);

        if ($customer === null || $customer->anonymized_at !== null) {
            return null;
        }

        try {
            $result = DB::transaction(function () use ($program, $booking, $customer): ?array {
                $member = $this->lockedMember($program, $customer, 'auto');

                if ($member === null || ! $member->isActive()) {
                    return null;
                }

                if (LoyaltyLedgerEntry::query()->where('program_id', $program->id)->where('booking_id', $booking->id)->where('kind', LoyaltyLedgerEntry::STAMP_EARNED)->exists()) {
                    return null;
                }

                $new = $member->wasRecentlyCreated;
                $member->stamps += 1;
                $member->lifetime_stamps += 1;
                $member->last_visit_at = now();
                $member->save();

                $entry = $this->record($program, $customer->id, LoyaltyLedgerEntry::STAMP_EARNED, 1, $member->stamps, bookingId: $booking->id);
                $issued = $this->issueDue($program, $member, $booking->id);

                return ['entry' => $entry, 'member' => $member, 'new' => $new, 'issued' => $issued];
            });
        } catch (UniqueConstraintViolationException) {
            return null; // un autre processus a déjà compté ce rendez-vous
        }

        if ($result === null) {
            return null;
        }

        $this->notifyProgress($program, $customer, $result['member'], $result['new'], $result['issued']);

        return $result['entry'];
    }

    /**
     * Le paiement d'un rendez-vous a changé : un paiement annulé retire le tampon qu'il avait permis (si le programme l'exige) ;
     * un paiement reçu peut le faire gagner.
     */
    public function onPaymentChanged(Booking $booking): void
    {
        try {
            $program = LoyaltyProgram::query()->where('status', '!=', LoyaltyProgram::ARCHIVED)->first();

            if ($program === null || ! $program->require_payment) {
                return;
            }

            if ($booking->payment_status === 'paid_onsite') {
                $this->earn($booking);
            } else {
                $this->reverseBooking($booking, 'payment_cancelled');
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Retire le tampon gagné pour ce rendez-vous (paiement annulé, correction). Une seule annulation par rendez-vous. Si le tampon avait
     * déjà servi à obtenir une récompense non utilisée, cette récompense est annulée et les tampons rendus ; si elle est déjà
     * utilisée, le solde est ramené à zéro et le manque est inscrit au journal pour que l'équipe le voie.
     */
    public function reverseBooking(Booking $booking, string $reason, ?string $actorId = null): bool
    {
        $earned = LoyaltyLedgerEntry::query()->where('booking_id', $booking->id)->where('kind', LoyaltyLedgerEntry::STAMP_EARNED)->first();

        if ($earned === null) {
            return false;
        }

        $program = LoyaltyProgram::query()->find($earned->program_id);
        $customer = Customer::query()->find($earned->customer_id);

        if ($program === null || $customer === null) {
            return false;
        }

        return DB::transaction(function () use ($program, $customer, $booking, $reason, $actorId): bool {
            $member = LoyaltyMember::query()->where('program_id', $program->id)->where('customer_id', $customer->id)->lockForUpdate()->first();

            if ($member === null || LoyaltyLedgerEntry::query()->where('program_id', $program->id)->where('booking_id', $booking->id)->where('kind', LoyaltyLedgerEntry::STAMP_REVERSED)->exists()) {
                return false;
            }

            $member->stamps -= 1;
            $member->lifetime_stamps = max(0, $member->lifetime_stamps - 1);
            $shortfall = 0;

            if ($member->stamps < 0) {
                $void = LoyaltyReward::query()->where('program_id', $program->id)->where('customer_id', $customer->id)->where('status', LoyaltyReward::AVAILABLE)->orderByDesc('earned_at')->lockForUpdate()->first();

                if ($void !== null) {
                    $required = (int) ($void->snapshot['required_visits'] ?? $program->required_visits);
                    $void->forceFill(['status' => LoyaltyReward::VOID])->save();
                    $member->stamps += $required;
                    $member->rewards_earned = max(0, $member->rewards_earned - 1);
                    $this->record($program, $customer->id, LoyaltyLedgerEntry::REWARD_VOIDED, $required, $member->stamps, rewardId: $void->id, actorId: $actorId, reason: $reason);
                }

                if ($member->stamps < 0) {
                    $shortfall = -$member->stamps;
                    $member->stamps = 0;
                }
            }

            $member->save();
            $this->record($program, $customer->id, LoyaltyLedgerEntry::STAMP_REVERSED, -1, $member->stamps, bookingId: $booking->id, actorId: $actorId, reason: $reason, data: $shortfall > 0 ? ['shortfall' => $shortfall] : null);

            return true;
        });
    }

    /**
     * Ajustement manuel (ajout ou retrait de tampons) par le personnel autorisé : motif obligatoire, journal et audit. Peut attribuer
     * une récompense si le solde atteint le nombre de visites. Le solde ne peut pas devenir négatif.
     *
     * @throws InvalidArgumentException `reason_required`, `invalid_amount`, `negative_balance` ou `no_program`
     */
    public function adjust(Customer $customer, int $delta, string $reason, string $actorId): LoyaltyMember
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('reason_required');
        }

        if ($delta === 0 || abs($delta) > 50) {
            throw new InvalidArgumentException('invalid_amount');
        }

        $program = LoyaltyProgram::current();

        if ($program === null || $program->status === LoyaltyProgram::DRAFT) {
            throw new InvalidArgumentException('no_program');
        }

        $result = DB::transaction(function () use ($program, $customer, $delta, $reason, $actorId): array {
            $member = $this->lockedMember($program, $customer, 'staff', $actorId) ?? throw new InvalidArgumentException('no_program');

            if ($member->stamps + $delta < 0) {
                throw new InvalidArgumentException('negative_balance');
            }

            $member->stamps += $delta;
            $member->lifetime_stamps = max(0, $member->lifetime_stamps + $delta);
            $member->save();
            $this->record($program, $customer->id, LoyaltyLedgerEntry::MANUAL, $delta, $member->stamps, actorId: $actorId, reason: $reason);
            $issued = $delta > 0 ? $this->issueDue($program, $member, null) : [];

            Audit::record('loyalty.adjusted', 'user', $actorId, $customer->org_id, 'customers', $customer->id, ['delta' => $delta, 'balance' => $member->stamps], $reason);

            return ['member' => $member, 'issued' => $issued];
        });

        foreach ($result['issued'] as $reward) {
            $this->messenger->rewardEarned($program, $customer, $reward);
        }

        return $result['member'];
    }

    /** Inscrit un client à la main (utile quand l'inscription automatique est coupée) ; sans effet s'il l'est déjà. */
    public function enroll(Customer $customer, string $actorId): LoyaltyMember
    {
        $program = LoyaltyProgram::current();

        if ($program === null || $program->status === LoyaltyProgram::DRAFT) {
            throw new InvalidArgumentException('no_program');
        }

        $member = DB::transaction(function () use ($program, $customer, $actorId): LoyaltyMember {
            $member = $this->lockedMember($program, $customer, 'staff', $actorId) ?? throw new InvalidArgumentException('no_program');

            if (! $member->isActive()) {
                $this->rejoinLocked($program, $member, $actorId);
            }

            return $member;
        });

        if ($member->wasRecentlyCreated) {
            $this->messenger->joined($program, $customer, $member);
        }

        return $member;
    }

    /** Le client ne veut plus participer : ses tampons et récompenses déjà obtenues sont conservés mais il n'avance plus. */
    public function optOut(Customer $customer, string $actorId, ?string $reason = null): void
    {
        $program = LoyaltyProgram::current();

        if ($program === null) {
            throw new InvalidArgumentException('no_program');
        }

        DB::transaction(function () use ($program, $customer, $actorId, $reason): void {
            $member = $this->lockedMember($program, $customer, 'staff', $actorId) ?? throw new InvalidArgumentException('no_program');

            if (! $member->isActive()) {
                return;
            }

            $member->forceFill(['status' => LoyaltyMember::OPTED_OUT, 'opted_out_at' => CarbonImmutable::now()])->save();
            $this->record($program, $customer->id, LoyaltyLedgerEntry::OPTED_OUT, 0, $member->stamps, actorId: $actorId, reason: $reason);
            Audit::record('loyalty.opted_out', 'user', $actorId, $customer->org_id, 'customers', $customer->id);
        });
    }

    public function rejoin(Customer $customer, string $actorId): void
    {
        $program = LoyaltyProgram::current();

        if ($program === null) {
            throw new InvalidArgumentException('no_program');
        }

        DB::transaction(function () use ($program, $customer, $actorId): void {
            $member = LoyaltyMember::query()->where('program_id', $program->id)->where('customer_id', $customer->id)->lockForUpdate()->first();

            if ($member !== null && ! $member->isActive()) {
                $this->rejoinLocked($program, $member, $actorId);
            }
        });
    }

    /** Client effacé (RGPD) : il quitte le programme et ses récompenses disponibles sont annulées. */
    public function customerErased(Customer $customer): void
    {
        DB::transaction(function () use ($customer): void {
            foreach (LoyaltyMember::query()->where('customer_id', $customer->id)->lockForUpdate()->get() as $member) {
                $member->forceFill(['status' => LoyaltyMember::OPTED_OUT, 'opted_out_at' => $member->opted_out_at ?? CarbonImmutable::now()])->save();
            }

            LoyaltyReward::query()->where('customer_id', $customer->id)->where('status', LoyaltyReward::AVAILABLE)->update(['status' => LoyaltyReward::VOID, 'updated_at' => now()]);
        });
    }

    /**
     * Fusion de deux fiches clients en double : le solde, les récompenses disponibles et la date de dernière visite passent sur la fiche
     * conservée ; la fiche absorbée sort du programme. Le journal reste intact : deux lignes « ajustement » expliquent le transfert.
     * Aucune fonction de fusion n'existe encore dans Rendivoo : ce service est prêt pour elle.
     */
    public function mergeCustomers(Customer $from, Customer $into, string $actorId): void
    {
        DB::transaction(function () use ($from, $into, $actorId): void {
            $source = LoyaltyMember::query()->where('customer_id', $from->id)->lockForUpdate()->first();

            if ($source === null) {
                return;
            }

            $program = LoyaltyProgram::query()->findOrFail($source->program_id);
            $target = $this->lockedMember($program, $into, 'staff', $actorId) ?? throw new InvalidArgumentException('no_program');

            $moved = $source->stamps;
            $target->stamps += $moved;
            $target->lifetime_stamps += $source->lifetime_stamps;
            $target->rewards_earned += $source->rewards_earned;
            $target->last_visit_at = collect([$target->last_visit_at, $source->last_visit_at])->filter()->max();
            $target->save();

            LoyaltyReward::query()->where('customer_id', $from->id)->where('status', LoyaltyReward::AVAILABLE)->update(['customer_id' => $into->id]);
            $this->record($program, $from->id, LoyaltyLedgerEntry::MANUAL, -$moved, 0, actorId: $actorId, reason: 'merged_into_other_profile', data: ['into' => $into->id]);
            $this->record($program, $into->id, LoyaltyLedgerEntry::MANUAL, $moved, $target->stamps, actorId: $actorId, reason: 'merged_from_other_profile', data: ['from' => $from->id]);
            $source->forceFill(['stamps' => 0, 'status' => LoyaltyMember::OPTED_OUT, 'opted_out_at' => CarbonImmutable::now()])->save();
            $this->issueDue($program, $target, null);
            Audit::record('loyalty.merged', 'user', $actorId, $into->org_id, 'customers', $into->id, ['from' => $from->id, 'stamps' => $moved]);
        });
    }

    /**
     * Attribue leur récompense aux clients dont le solde atteint déjà le nombre de visites (nombre de visites baissé, programme réactivé).
     * Retourne le nombre de récompenses attribuées.
     */
    public function issueAllDue(LoyaltyProgram $program): int
    {
        $count = 0;
        $ids = LoyaltyMember::query()->where('program_id', $program->id)->where('status', LoyaltyMember::ACTIVE)->where('stamps', '>=', $program->required_visits)->pluck('id');

        foreach ($ids as $id) {
            $issued = DB::transaction(function () use ($program, $id): array {
                $member = LoyaltyMember::query()->whereKey($id)->lockForUpdate()->first();

                return $member === null ? [] : $this->issueDue($program, $member, null);
            });

            $count += count($issued);
            $customer = $issued === [] ? null : Customer::query()->find($issued[0]->customer_id);

            if ($customer !== null) {
                $this->messenger->rewardEarned($program, $customer, $issued[array_key_last($issued)]);
            }
        }

        return $count;
    }

    /** Un rendez-vous est-il éligible d'après la règle de gain du programme ? */
    public function eligible(LoyaltyProgram $program, Booking $booking): bool
    {
        if ($booking->status !== Booking::COMPLETED) {
            return false;
        }

        $services = $program->serviceIds('earn');

        if ($services !== null && ! in_array($booking->service_id, $services, true)) {
            return false;
        }

        if ($program->min_amount_cents !== null && $booking->price_cents < $program->min_amount_cents) {
            return false;
        }

        return ! $program->require_payment || $booking->payment_status === 'paid_onsite';
    }

    /**
     * Attribue autant de récompenses que le solde le permet (le surplus reste). À appeler dans une transaction, ligne client verrouillée.
     *
     * @return list<LoyaltyReward>
     */
    private function issueDue(LoyaltyProgram $program, LoyaltyMember $member, ?string $bookingId): array
    {
        $issued = [];

        while ($member->stamps >= $program->required_visits) {
            $member->stamps -= $program->required_visits;
            $member->rewards_earned += 1;
            $member->save();

            $reward = LoyaltyReward::query()->create([
                'org_id' => $program->org_id,
                'program_id' => $program->id,
                'customer_id' => $member->customer_id,
                'status' => LoyaltyReward::AVAILABLE,
                'snapshot' => $program->rewardSnapshot(),
                'earned_booking_id' => $bookingId,
                'earned_at' => CarbonImmutable::now(),
                'expires_at' => $program->expiry_days === null ? null : CarbonImmutable::now()->addDays($program->expiry_days),
            ]);

            $this->record($program, $member->customer_id, LoyaltyLedgerEntry::REWARD_ISSUED, -$program->required_visits, $member->stamps, bookingId: $bookingId, rewardId: $reward->id);
            $issued[] = $reward;
        }

        return $issued;
    }

    /** Ligne du client dans le programme, créée si besoin (inscription automatique, ou par le personnel). Null : pas d'inscription automatique. */
    private function lockedMember(LoyaltyProgram $program, Customer $customer, string $via, ?string $actorId = null): ?LoyaltyMember
    {
        $find = fn (): ?LoyaltyMember => LoyaltyMember::query()->where('program_id', $program->id)->where('customer_id', $customer->id)->lockForUpdate()->first();
        $member = $find();

        if ($member !== null) {
            return $member;
        }

        if ($via === 'auto' && ! $program->auto_enroll) {
            return null;
        }

        DB::table('loyalty_members')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'org_id' => $program->org_id,
            'program_id' => $program->id,
            'customer_id' => $customer->id,
            'joined_via' => $via,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $member = $find();

        if ($member !== null && $member->stamps === 0 && $member->lifetime_stamps === 0 && ! LoyaltyLedgerEntry::query()->where('program_id', $program->id)->where('customer_id', $customer->id)->where('kind', LoyaltyLedgerEntry::ENROLLED)->exists()) {
            $member->wasRecentlyCreated = true;
            $this->record($program, $customer->id, LoyaltyLedgerEntry::ENROLLED, 0, 0, actorId: $via === 'staff' ? $actorId : null, data: ['via' => $via]);
        }

        return $member;
    }

    private function rejoinLocked(LoyaltyProgram $program, LoyaltyMember $member, string $actorId): void
    {
        $member->forceFill(['status' => LoyaltyMember::ACTIVE, 'opted_out_at' => null])->save();
        $this->record($program, $member->customer_id, LoyaltyLedgerEntry::REJOINED, 0, $member->stamps, actorId: $actorId);
        Audit::record('loyalty.rejoined', 'user', $actorId, $program->org_id, 'customers', $member->customer_id);
    }

    /**
     * @param  list<LoyaltyReward>  $issued
     */
    private function notifyProgress(LoyaltyProgram $program, Customer $customer, LoyaltyMember $member, bool $new, array $issued): void
    {
        if ($new) {
            $this->messenger->joined($program, $customer, $member);
        }

        if ($issued !== []) {
            $this->messenger->rewardEarned($program, $customer, $issued[array_key_last($issued)]);

            return;
        }

        $this->messenger->progress($program, $customer, $member);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function record(
        LoyaltyProgram $program,
        ?string $customerId,
        string $kind,
        int $delta,
        ?int $balance,
        ?string $bookingId = null,
        ?string $rewardId = null,
        ?string $actorId = null,
        ?string $reason = null,
        ?array $data = null,
    ): LoyaltyLedgerEntry {
        return LoyaltyLedgerEntry::query()->create([
            'org_id' => $program->org_id,
            'program_id' => $program->id,
            'customer_id' => $customerId,
            'kind' => $kind,
            'stamps_delta' => $delta,
            'balance_after' => $balance,
            'booking_id' => $bookingId,
            'reward_id' => $rewardId,
            'actor_type' => $actorId === null ? 'system' : 'user',
            'actor_id' => $actorId,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'data' => $data,
            'created_at' => now(),
        ]);
    }
}
