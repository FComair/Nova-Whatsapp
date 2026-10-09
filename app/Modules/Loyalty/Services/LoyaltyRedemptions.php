<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Services;

use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Models\LoyaltyLedgerEntry;
use App\Modules\Loyalty\Models\LoyaltyProgram;
use App\Modules\Loyalty\Models\LoyaltyReward;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Utilisation des récompenses (docs/27). Règles :
 * - une récompense ne s'utilise qu'une fois (verrou de la ligne + index unique par rendez-vous) ;
 * - refusée si elle est expirée, déjà utilisée, annulée, si le rendez-vous n'est pas celui du client, n'est pas confirmé ou terminé,
 *   n'est pas un service visé, ou — quand l'établissement a choisi « à la visite suivante » — est celui qui l'a fait gagner ;
 * - une récompense non cumulable est refusée si le personnel déclare qu'une autre offre s'applique ;
 * - l'utilisation NE MODIFIE PAS le prix du rendez-vous ni l'encaissé : elle enregistre la valeur de la récompense et l'écran indique
 *   le montant à encaisser (prix − récompense) ; le paiement se saisit comme d'habitude, donc aucun chiffre financier existant ne bouge ;
 * - l'annulation d'une utilisation par erreur exige un motif ; tout est journalisé (journal de fidélité et audit).
 */
final class LoyaltyRedemptions
{
    public function __construct(private readonly LoyaltyEngine $engine, private readonly LoyaltyMessenger $messenger) {}

    /**
     * @throws InvalidArgumentException code de refus : `not_available`, `expired`, `booking_required`, `booking_mismatch`, `booking_status`,
     *                                  `service_not_eligible`, `same_visit`, `not_combinable`, `already_applied`
     */
    public function redeem(LoyaltyReward $reward, ?Booking $booking, string $actorId, bool $otherOffer = false): LoyaltyReward
    {
        try {
            $result = DB::transaction(function () use ($reward, $booking, $actorId, $otherOffer): LoyaltyReward {
                $locked = LoyaltyReward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();
                $this->assertRedeemable($locked, $booking, $otherOffer);

                $value = $booking === null ? 0 : $this->valueFor($locked, $booking->price_cents);
                $locked->forceFill([
                    'status' => LoyaltyReward::REDEEMED,
                    'redeemed_at' => CarbonImmutable::now(),
                    'redeemed_by' => $actorId,
                    'redeemed_booking_id' => $booking?->id,
                    'applied_value_cents' => $value,
                ])->save();

                $program = LoyaltyProgram::query()->findOrFail($locked->program_id);
                $this->engine->record($program, $locked->customer_id, LoyaltyLedgerEntry::REWARD_REDEEMED, 0, null, bookingId: $booking?->id, rewardId: $locked->id, actorId: $actorId, data: ['value_cents' => $value]);
                Audit::record('loyalty.redeemed', 'user', $actorId, $locked->org_id, 'loyalty_rewards', $locked->id, ['booking_id' => $booking?->id, 'value_cents' => $value]);

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw new InvalidArgumentException('already_applied'); // la base a refusé un second usage sur le même rendez-vous
        }

        $this->afterRedeem($result);

        return $result;
    }

    /**
     * Annule une utilisation faite par erreur : la récompense redevient disponible (ou expirée si son délai est passé entre-temps).
     *
     * @throws InvalidArgumentException `reason_required` ou `not_redeemed`
     */
    public function reverse(LoyaltyReward $reward, string $actorId, string $reason): LoyaltyReward
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new InvalidArgumentException('reason_required');
        }

        return DB::transaction(function () use ($reward, $actorId, $reason): LoyaltyReward {
            $locked = LoyaltyReward::query()->whereKey($reward->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== LoyaltyReward::REDEEMED) {
                throw new InvalidArgumentException('not_redeemed');
            }

            $before = ['booking_id' => $locked->redeemed_booking_id, 'value_cents' => $locked->applied_value_cents, 'redeemed_at' => $locked->redeemed_at?->toIso8601String()];
            $expired = $locked->expires_at !== null && $locked->expires_at->isPast();
            $locked->forceFill([
                'status' => $expired ? LoyaltyReward::EXPIRED : LoyaltyReward::AVAILABLE,
                'redeemed_at' => null,
                'redeemed_by' => null,
                'redeemed_booking_id' => null,
                'applied_value_cents' => null,
            ])->save();

            $program = LoyaltyProgram::query()->findOrFail($locked->program_id);
            $this->engine->record($program, $locked->customer_id, LoyaltyLedgerEntry::REDEMPTION_REVERSED, 0, null, bookingId: $before['booking_id'], rewardId: $locked->id, actorId: $actorId, reason: $reason, data: [...$before, 'now_expired' => $expired]);
            Audit::record('loyalty.redemption_reversed', 'user', $actorId, $locked->org_id, 'loyalty_rewards', $locked->id, $before, $reason);

            return $locked;
        });
    }

    /**
     * Contrôle des conditions d'utilisation, sans rien écrire (sert aussi à l'écran pour griser ce qui est impossible).
     *
     * @throws InvalidArgumentException
     */
    public function assertRedeemable(LoyaltyReward $reward, ?Booking $booking, bool $otherOffer = false): void
    {
        if ($reward->status === LoyaltyReward::EXPIRED || ($reward->status === LoyaltyReward::AVAILABLE && $reward->expires_at !== null && $reward->expires_at->isPast())) {
            throw new InvalidArgumentException('expired');
        }

        if ($reward->status !== LoyaltyReward::AVAILABLE) {
            throw new InvalidArgumentException('not_available');
        }

        $snapshot = $reward->snapshot;

        if (! (bool) ($snapshot['combinable'] ?? false) && $otherOffer) {
            throw new InvalidArgumentException('not_combinable');
        }

        if ($booking === null) {
            if ((bool) ($snapshot['requires_appointment'] ?? true)) {
                throw new InvalidArgumentException('booking_required');
            }

            return;
        }

        if ($booking->customer_id !== $reward->customer_id) {
            throw new InvalidArgumentException('booking_mismatch');
        }

        if (! in_array($booking->status, [Booking::CONFIRMED, Booking::COMPLETED], true)) {
            throw new InvalidArgumentException('booking_status');
        }

        $services = $snapshot['service_ids'] ?? null;

        if (is_array($services) && ! in_array($booking->service_id, $services, true)) {
            throw new InvalidArgumentException('service_not_eligible');
        }

        if (($snapshot['redeem_timing'] ?? 'next_visit') === 'next_visit' && $booking->id === $reward->earned_booking_id) {
            throw new InvalidArgumentException('same_visit');
        }

        if (LoyaltyReward::query()->where('redeemed_booking_id', $booking->id)->where('status', LoyaltyReward::REDEEMED)->exists()) {
            throw new InvalidArgumentException('already_applied');
        }
    }

    /**
     * Valeur de la récompense pour un prix de service, en centimes : service offert (plafonné), pourcentage (plafonné), montant fixe
     * (jamais plus que le prix) ; une récompense personnalisée n'a pas de valeur chiffrée.
     */
    public function valueFor(LoyaltyReward $reward, int $priceCents): int
    {
        $snapshot = $reward->snapshot;
        $max = isset($snapshot['max_cents']) ? (int) $snapshot['max_cents'] : null;
        $value = (int) ($snapshot['value'] ?? 0);

        $amount = match ($snapshot['type'] ?? 'custom') {
            'free_service' => $priceCents,
            'percent_discount' => intdiv($priceCents * min(100, $value), 100),
            'fixed_amount' => min($value, $priceCents),
            default => 0,
        };

        return max(0, min($amount, $priceCents, $max ?? $amount));
    }

    /** Passe en « expirée » les récompenses dont le délai est dépassé (tâche planifiée). Retourne leur nombre. */
    public function expireDue(): int
    {
        $count = 0;

        foreach (LoyaltyReward::query()->where('status', LoyaltyReward::AVAILABLE)->whereNotNull('expires_at')->where('expires_at', '<', CarbonImmutable::now())->get() as $reward) {
            DB::transaction(function () use ($reward, &$count): void {
                $locked = LoyaltyReward::query()->whereKey($reward->id)->lockForUpdate()->first();

                if ($locked === null || $locked->status !== LoyaltyReward::AVAILABLE) {
                    return;
                }

                $locked->forceFill(['status' => LoyaltyReward::EXPIRED])->save();
                $program = LoyaltyProgram::query()->findOrFail($locked->program_id);
                $this->engine->record($program, $locked->customer_id, LoyaltyLedgerEntry::REWARD_EXPIRED, 0, null, rewardId: $locked->id);
                $count++;
            });
        }

        return $count;
    }

    /** Rappel avant expiration (messages facultatifs, voir LoyaltyMessenger) : une seule fois par récompense. Retourne le nombre envoyé. */
    public function remindExpiring(): int
    {
        $sent = 0;

        foreach (LoyaltyReward::query()->where('status', LoyaltyReward::AVAILABLE)->whereNotNull('expires_at')->whereNull('expiry_reminded_at')->where('expires_at', '>', CarbonImmutable::now())->get() as $reward) {
            $program = LoyaltyProgram::query()->find($reward->program_id);

            if ($program === null || $reward->expires_at === null || $reward->expires_at->gt(CarbonImmutable::now()->addDays($program->expiry_reminder_days))) {
                continue;
            }

            $claimed = LoyaltyReward::query()->whereKey($reward->id)->whereNull('expiry_reminded_at')->update(['expiry_reminded_at' => CarbonImmutable::now()]);
            $customer = Customer::query()->find($reward->customer_id);

            if ($claimed === 1 && $customer !== null && $this->messenger->expiryReminder($program, $customer, $reward)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function afterRedeem(LoyaltyReward $reward): void
    {
        $program = LoyaltyProgram::query()->find($reward->program_id);
        $customer = Customer::query()->find($reward->customer_id);

        if ($program !== null && $customer !== null) {
            $this->messenger->rewardRedeemed($program, $customer, $reward);
        }
    }
}
