<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Models\BookingEvent;
use App\Modules\Booking\Models\BookingTip;
use App\Modules\Booking\Models\BookingTipAllocation;
use App\Modules\Booking\Support\Actor;
use App\Modules\Staff\Models\Practitioner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pourboires (docs/24). Le pourboire est un montant À PART : il ne modifie ni le prix du rendez-vous ni le montant encaissé, donc
 * aucun chiffre financier existant. Règles :
 * - un seul pourboire par rendez-vous (le paiement du rendez-vous est lui-même unique) ; l'enregistrer à nouveau le MODIFIE ;
 * - un montant nul ou vide n'est pas un pourboire : il supprime l'éventuel pourboire existant ;
 * - il faut qu'un paiement ait été reçu (payé, partiel ou offert) ; « non payé » et « perte » refusent un pourboire ;
 * - sans répartition donnée, le pourboire revient au praticien attribué ; s'il n'est plus actif, un bénéficiaire doit être choisi ;
 * - avec une répartition, chaque part est positive, chaque bénéficiaire est un praticien actif (une seule fois) et les parts
 *   totalisent exactement le pourboire ;
 * - tout ajout, modification, changement de répartition ou suppression est journalisé (audit_logs et booking_events) avec les
 *   valeurs avant et après.
 *
 * @phpstan-type Allocation array{practitioner_id: string, amount_cents: int}
 * @phpstan-type Snapshot array{amount_cents: int, allocations: list<Allocation>}
 */
final class BookingTips
{
    /** Paiements qui autorisent un pourboire : de l'argent a été reçu, ou le service était offert et le client a tout de même remercié. */
    public const PAYMENT_STATUSES = ['paid_onsite', 'partially_paid', 'waived'];

    /**
     * @param  list<Allocation>|null  $allocations  null ou vide : tout au praticien attribué
     *
     * @throws BookingRuleViolation
     */
    public function record(Booking $booking, ?int $amountCents, ?array $allocations, Actor $actor): ?BookingTip
    {
        if (! in_array($booking->status, [Booking::CONFIRMED, Booking::COMPLETED, Booking::NO_SHOW], true)) {
            throw new BookingRuleViolation(BookingRuleViolation::INVALID_TRANSITION);
        }

        $amount = $amountCents ?? 0;

        return DB::transaction(function () use ($booking, $amount, $allocations, $actor): ?BookingTip {
            $existing = BookingTip::query()->where('booking_id', $booking->id)->lockForUpdate()->first();
            $before = $this->snapshot($existing);

            if ($amount <= 0) {
                if ($existing !== null) {
                    $existing->delete(); // les parts partent avec le pourboire (cascade)
                    $this->journal($booking, $actor, 'removed', $before, null);
                }

                return null;
            }

            if (! in_array($booking->payment_status, self::PAYMENT_STATUSES, true)) {
                throw new BookingRuleViolation(BookingRuleViolation::TIP_REQUIRES_PAYMENT);
            }

            $rows = $this->resolve($booking, $amount, $allocations);

            $tip = $existing ?? new BookingTip([
                'org_id' => $booking->org_id,
                'booking_id' => $booking->id,
                'customer_id' => $booking->customer_id,
                'currency' => $booking->currency,
                'paid_at' => CarbonImmutable::now(),
                'recorded_by' => $actor->type === 'user' ? $actor->id : null,
            ]);
            $tip->amount_cents = $amount;
            $tip->save();

            BookingTipAllocation::query()->where('tip_id', $tip->id)->delete();

            foreach ($rows as $row) {
                BookingTipAllocation::query()->create(['org_id' => $booking->org_id, 'tip_id' => $tip->id, 'booking_id' => $booking->id, ...$row]);
            }

            // La base vérifie que les parts totalisent le pourboire ; on le fait contrôler tout de suite plutôt qu'à la fin de la
            // transaction, pour que l'erreur remonte ici.
            DB::statement('SET CONSTRAINTS booking_tips_allocations_sum, booking_tip_allocations_sum IMMEDIATE');
            DB::statement('SET CONSTRAINTS booking_tips_allocations_sum, booking_tip_allocations_sum DEFERRED');

            $after = $this->snapshot($tip);
            $action = $after === null ? null : $this->action($before, $after);

            if ($action !== null) {
                $this->journal($booking, $actor, $action, $before, $after);
            }

            return $tip;
        });
    }

    /**
     * Parts du pourboire : validées, ou tout au praticien attribué par défaut.
     *
     * @param  list<Allocation>|null  $allocations
     * @return list<Allocation>
     *
     * @throws BookingRuleViolation
     */
    private function resolve(Booking $booking, int $amount, ?array $allocations): array
    {
        if ($allocations === null || $allocations === []) {
            $assigned = Practitioner::query()->find($booking->practitioner_id);

            if ($assigned === null || $assigned->status !== Practitioner::STATUS_ACTIVE) {
                throw new BookingRuleViolation(BookingRuleViolation::TIP_RECIPIENT_REQUIRED);
            }

            return [['practitioner_id' => $assigned->id, 'amount_cents' => $amount]];
        }

        $ids = array_column($allocations, 'practitioner_id');

        if (count($ids) !== count(array_unique($ids)) || Practitioner::query()->whereIn('id', $ids)->where('status', Practitioner::STATUS_ACTIVE)->count() !== count($ids)) {
            throw new BookingRuleViolation(BookingRuleViolation::TIP_RECIPIENT_INVALID);
        }

        foreach ($allocations as $row) {
            if ($row['amount_cents'] <= 0) {
                throw new BookingRuleViolation(BookingRuleViolation::TIP_ALLOCATION_MISMATCH);
            }
        }

        if (array_sum(array_column($allocations, 'amount_cents')) !== $amount) {
            throw new BookingRuleViolation(BookingRuleViolation::TIP_ALLOCATION_MISMATCH);
        }

        return array_map(fn (array $row): array => ['practitioner_id' => $row['practitioner_id'], 'amount_cents' => $row['amount_cents']], $allocations);
    }

    /**
     * @return Snapshot|null
     */
    public function snapshot(?BookingTip $tip): ?array
    {
        if ($tip === null) {
            return null;
        }

        $allocations = $tip->allocations()->get()->map(fn (BookingTipAllocation $row): array => ['practitioner_id' => $row->practitioner_id, 'amount_cents' => $row->amount_cents])->all();
        usort($allocations, fn (array $a, array $b): int => strcmp($a['practitioner_id'], $b['practitioner_id']));

        return ['amount_cents' => $tip->amount_cents, 'allocations' => $allocations];
    }

    /**
     * Nature de la modification d'un pourboire qui existe après l'opération ; null si rien n'a changé. (La suppression est traitée
     * à part.)
     *
     * @param  Snapshot|null  $before
     * @param  Snapshot  $after
     */
    private function action(?array $before, array $after): ?string
    {
        return match (true) {
            $before === null => 'added',
            $before['amount_cents'] !== $after['amount_cents'] => 'changed',
            $before['allocations'] !== $after['allocations'] => 'reallocated',
            default => null,
        };
    }

    /**
     * Journalise : audit_logs (qui, quand, quoi, avant/après) et l'historique du rendez-vous (booking_events, ajout seul).
     *
     * @param  Snapshot|null  $before
     * @param  Snapshot|null  $after
     */
    private function journal(Booking $booking, Actor $actor, string $action, ?array $before, ?array $after): void
    {
        $diff = ['action' => $action, 'from' => $before, 'to' => $after, 'currency' => $booking->currency, 'payment_status' => $booking->payment_status];

        BookingEvent::query()->create(['org_id' => $booking->org_id, 'booking_id' => $booking->id, 'actor_type' => $actor->type, 'actor_id' => $actor->id, 'action' => 'tip_updated', 'diff' => $diff]);
        Audit::record("booking.tip.{$action}", $actor->type === 'user' ? 'user' : 'system', $actor->id, $booking->org_id, 'booking', $booking->id, $diff);
    }
}
