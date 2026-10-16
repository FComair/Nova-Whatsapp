<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Support\MessageTemplateType;
use App\Modules\Whatsapp\Support\SendRefused;
use App\Modules\Whatsapp\Support\TransportFailed;
use Carbon\CarbonImmutable;

/**
 * Rappels de rendez-vous par WhatsApp (modèle approuvé par Meta, seul autorisé hors fenêtre de 24 h).
 *
 * Règles :
 * - délai réglé dans les politiques (`reminder_hours_before`, 0 = aucun rappel) ;
 * - uniquement les rendez-vous confirmés et à venir ; jamais deux rappels (réservation atomique par le cœur de réservation) ;
 * - pas de rappel pour un rendez-vous pris à l'intérieur du délai de rappel (le client vient de le réserver) ;
 * - jamais après un STOP ; comptes suspendus, en pause ou en suppression ignorés ;
 * - échec : 3 tentatives au plus, une par passage.
 */
final class ReminderService
{
    public const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PolicyService $policies,
        private readonly BookingCore $core,
        private readonly BookingMessageSender $sender,
    ) {}

    /**
     * À exécuter sous le contexte de l'organisation.
     *
     * @return int nombre de rappels envoyés
     */
    public function sendDue(Organization $organization): int
    {
        $hours = $this->policies->for($organization)->reminder_hours_before;

        if ($hours <= 0 || ! in_array($organization->status, [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE], true)) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $sent = 0;

        $due = Booking::query()->where('status', Booking::CONFIRMED)->whereNull('reminder_sent_at')
            ->where('reminder_attempts', '<', self::MAX_ATTEMPTS)
            ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->addHours($hours))
            ->whereRaw('created_at <= starts_at - make_interval(hours => ?)', [$hours])
            ->orderBy('starts_at')->limit(200)->get();

        foreach ($due as $booking) {
            $sent += $this->remind($organization, $booking) ? 1 : 0;
        }

        return $sent;
    }

    private function remind(Organization $organization, Booking $booking): bool
    {
        $customer = Customer::query()->find($booking->customer_id);

        if ($customer === null || $customer->whatsapp_opted_out_at !== null || ! $this->core->claimReminder($booking)) {
            return false;
        }

        $language = in_array($customer->language, (array) config('nova.locales'), true) ? (string) $customer->language : (string) config('app.fallback_locale');
        try {
            $this->sender->send($organization, $booking, $customer, MessageTemplateType::REMINDER, $language);
        } catch (SendRefused|TransportFailed $e) {
            $this->core->releaseReminder($booking, $e instanceof TransportFailed ? $e->errorCode : $e->reason);

            return false;
        }

        return true;
    }
}
