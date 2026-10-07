<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Admin\Notifications\AccountStatusNotification;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Audit\Audit;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Support\OwnerContact;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Booking\Services\BookingNotifier;
use App\Modules\Booking\Support\Actor;
use App\Modules\Tenancy\Exceptions\PauseRefused;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Notifications\PauseNotification;
use App\Modules\Tenancy\Support\StatusRestorer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pause d'établissement (flowchart 4B), à la demande du propriétaire. À exécuter sous le contexte de l'organisation.
 *
 * - Durée : de 1 jour à `pause_max_days` (90 par défaut, `platform_settings`).
 * - Pendant la pause : dashboard limité (réactivation, export, suppression), bot indisponible avec le message d'absence, rappels arrêtés,
 *   facturation suspendue. À la reprise, l'essai et la période payée sont prolongés de la durée de la pause : on ne paie pas la pause.
 * - Rendez-vous prévus pendant la pause : la pause est refusée, SAUF si le propriétaire choisit de les annuler ; les clients sont alors
 *   prévenus par WhatsApp (au mieux, voir `BookingNotifier`) et le propriétaire voit combien n'ont pas pu l'être.
 * - Fin de la pause (échéance atteinte) : le compte est **suspendu** (raison `system.pause_ended`), après un rappel quelques jours avant ;
 *   le propriétaire peut le réactiver ou en demander la suppression (décision du fondateur, `accounts:end-pauses`).
 */
final class AccountPause
{
    /** Statuts depuis lesquels le propriétaire peut mettre son compte en pause. */
    public const STARTABLE = [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE];

    /** Jours avant la fin de la pause où le propriétaire est prévenu. */
    public const REMINDER_DAYS = 7;

    /** Raison de la suspension qui suit une pause arrivée à échéance. */
    public const EXPIRED_REASON = 'system.pause_ended';

    public function __construct(
        private readonly PlatformSettings $settings,
        private readonly BookingCore $core,
        private readonly BookingNotifier $notifier,
    ) {}

    public function maxDays(): int
    {
        return $this->settings->integer('pause_max_days');
    }

    /**
     * Rendez-vous (en attente ou confirmés) prévus entre maintenant et la fin de la pause envisagée.
     *
     * @return Collection<int, Booking>
     */
    public function upcomingBookings(int $days): Collection
    {
        return Booking::query()
            ->whereIn('status', Booking::BLOCKING)
            ->where('starts_at', '>=', now())
            ->where('starts_at', '<', CarbonImmutable::now()->addDays($days))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return array{cancelled: int, notified: int, unreachable: int} rendez-vous annulés, clients prévenus, clients non joignables par WhatsApp
     *
     * @throws PauseRefused
     */
    public function start(Organization $organization, int $days, ?string $message, string $userId, bool $cancelBookings = false): array
    {
        if (! in_array($organization->status, self::STARTABLE, true)) {
            throw new PauseRefused('not_available');
        }

        if ($days < 1 || $days > $this->maxDays()) {
            throw new PauseRefused('days', (string) $this->maxDays());
        }

        $upcoming = $this->upcomingBookings($days);

        if ($upcoming->isNotEmpty() && ! $cancelBookings) {
            throw new PauseRefused('upcoming_bookings', (string) $upcoming->count());
        }

        $until = CarbonImmutable::now()->addDays($days);
        $message = $message === null ? '' : trim($message);
        $actor = Actor::user($userId);

        // Annulations et pause sont écrites ensemble ; les messages WhatsApp partent après, jamais dans la transaction.
        DB::transaction(function () use ($organization, $upcoming, $actor, $userId, $days, $until, $message): void {
            foreach ($upcoming as $booking) {
                $this->core->cancel($organization, $booking, $actor, 'organization_paused');
            }

            $organization->forceFill([
                'previous_status' => $organization->status,
                'status' => Organization::STATUS_PAUSED,
                'paused_at' => now(),
                'pause_until' => $until,
                'pause_reminder_sent_at' => null,
                'unavailable_message' => $message === '' ? null : [$organization->locale => $message],
            ])->save();

            Audit::record('organization.paused', 'user', $userId, $organization->id, 'organizations', $organization->id, ['until' => $until->toDateString(), 'days' => $days, 'bookings_cancelled' => $upcoming->count()]);
        });

        $notified = $upcoming->filter(fn (Booking $booking): bool => $this->notifier->cancelled($organization, $booking))->count();

        OwnerContact::notify(new PauseNotification(PauseNotification::STARTED, $organization->name, $until, OwnerContact::locale()));

        return ['cancelled' => $upcoming->count(), 'notified' => $notified, 'unreachable' => $upcoming->count() - $notified];
    }

    /**
     * Réactive le compte, à la demande du propriétaire (`$userId`), depuis la pause ou depuis la suspension qui l'a suivie.
     *
     * @throws PauseRefused
     */
    public function resume(Organization $organization, string $userId): void
    {
        $expired = $organization->status === Organization::STATUS_SUSPENDED && $organization->suspension_reason === self::EXPIRED_REASON;

        if ($organization->status !== Organization::STATUS_PAUSED && ! $expired) {
            throw new PauseRefused('not_paused');
        }

        // En pause : on décale d'autant de temps que la pause a duré jusqu'ici. Après l'échéance, le décalage a déjà été appliqué.
        $trialEndsAt = $organization->trial_ends_at;

        if (! $expired) {
            $paused = (int) max(0, now()->getTimestamp() - ($organization->paused_at?->getTimestamp() ?? now()->getTimestamp()));
            $trialEndsAt = $this->shiftBilling($organization, $paused);
        }

        $organization->forceFill([
            'status' => StatusRestorer::after($organization->previous_status, $trialEndsAt),
            'trial_ends_at' => $trialEndsAt,
            'previous_status' => null,
            'paused_at' => null,
            'pause_until' => null,
            'pause_reminder_sent_at' => null,
            'unavailable_message' => null,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        Audit::record('organization.pause_ended', 'user', $userId, $organization->id, 'organizations', $organization->id, ['automatic' => false, 'after_expiry' => $expired]);
    }

    /**
     * Passage périodique pour UNE organisation : en pause (rappel avant l'échéance, puis suspension) ou suspendue à la fin de sa pause
     * (rappel, puis suppression programmée automatiquement au bout de `suspension_max_days`).
     *
     * @return 'reminded'|'expired'|'deletion_reminded'|'deletion_scheduled'|null
     */
    public function process(Organization $organization): ?string
    {
        if ($organization->status === Organization::STATUS_SUSPENDED && $organization->suspension_reason === self::EXPIRED_REASON) {
            return $this->processExpiredSuspension($organization);
        }

        if ($organization->status !== Organization::STATUS_PAUSED || $organization->pause_until === null) {
            return null;
        }

        if ($organization->pause_until->lte(now())) {
            $this->expire($organization);

            return 'expired';
        }

        if ($organization->pause_reminder_sent_at === null && $organization->pause_until->lte(now()->addDays(self::REMINDER_DAYS))) {
            $organization->forceFill(['pause_reminder_sent_at' => now()])->save();
            OwnerContact::notify(new PauseNotification(PauseNotification::ENDING, $organization->name, $organization->pause_until, OwnerContact::locale()));

            return 'reminded';
        }

        return null;
    }

    /**
     * Décision du fondateur : un compte resté suspendu après sa pause voit sa suppression programmée automatiquement après
     * `suspension_max_days` (60 par défaut), avec un rappel quelques jours avant. La suppression suit ensuite le délai habituel
     * (annulable par le propriétaire tant que l'effacement n'a pas commencé).
     */
    private function processExpiredSuspension(Organization $organization): ?string
    {
        if ($organization->suspended_at === null) {
            return null;
        }

        $deadline = CarbonImmutable::instance($organization->suspended_at)->addDays($this->settings->integer('suspension_max_days'));

        if ($deadline->lte(now())) {
            $scheduled = CarbonImmutable::now()->addDays($this->settings->integer('deletion_delay_days'));

            $organization->forceFill([
                'previous_status' => Organization::STATUS_SUSPENDED,
                'status' => Organization::STATUS_PENDING_DELETION,
                'deletion_requested_at' => now(),
                'deletion_scheduled_for' => $scheduled,
                'pause_reminder_sent_at' => null,
            ])->save();

            Audit::record('organization.deletion_scheduled', 'system', null, $organization->id, 'organizations', $organization->id, ['automatic' => true, 'reason' => self::EXPIRED_REASON, 'scheduled_for' => $scheduled->toDateString()]);
            OwnerContact::notify(new AccountStatusNotification('organization.deletion_scheduled', $organization->name, null, $scheduled, OwnerContact::locale()));

            return 'deletion_scheduled';
        }

        if ($organization->pause_reminder_sent_at === null && $deadline->lte(now()->addDays(self::REMINDER_DAYS))) {
            $organization->forceFill(['pause_reminder_sent_at' => now()])->save();
            OwnerContact::notify(new PauseNotification(PauseNotification::SUSPENSION_ENDING, $organization->name, $deadline, OwnerContact::locale()));

            return 'deletion_reminded';
        }

        return null;
    }

    /**
     * Fin de la durée choisie : la pause (non facturée) est comptée en entier, puis le compte est suspendu. Le propriétaire est prévenu
     * et peut le réactiver ou demander sa suppression ; sans réponse, le compte reste suspendu (données conservées).
     */
    private function expire(Organization $organization): void
    {
        $paused = (int) max(0, ($organization->pause_until?->getTimestamp() ?? 0) - ($organization->paused_at?->getTimestamp() ?? 0));
        $trialEndsAt = $this->shiftBilling($organization, $paused);

        $organization->forceFill([
            'status' => Organization::STATUS_SUSPENDED,
            'trial_ends_at' => $trialEndsAt,
            'suspended_at' => now(),
            'suspension_reason' => self::EXPIRED_REASON,
            'paused_at' => null,
            'pause_until' => null,
            'pause_reminder_sent_at' => null,
            'unavailable_message' => null,
        ])->save();

        Audit::record('organization.pause_expired', 'system', null, $organization->id, 'organizations', $organization->id);
        OwnerContact::notify(new PauseNotification(PauseNotification::ENDED, $organization->name, null, OwnerContact::locale()));
    }

    /** La pause n'est pas facturée : essai et période payée sont décalés d'autant. Renvoie la nouvelle fin d'essai. */
    private function shiftBilling(Organization $organization, int $seconds): ?CarbonImmutable
    {
        Subscription::query()->get()->each(function (Subscription $subscription) use ($seconds): void {
            $subscription->forceFill([
                'current_period_start' => $subscription->current_period_start->copy()->addSeconds($seconds),
                'current_period_end' => $subscription->current_period_end->copy()->addSeconds($seconds),
                'cancel_at' => $subscription->cancel_at?->copy()->addSeconds($seconds),
            ])->save();
        });

        return $organization->trial_ends_at === null ? null : CarbonImmutable::instance($organization->trial_ends_at)->addSeconds($seconds);
    }
}
