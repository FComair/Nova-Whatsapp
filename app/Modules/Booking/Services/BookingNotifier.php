<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Support\MessageTemplateType;
use App\Modules\Whatsapp\Support\SendRefused;
use App\Modules\Whatsapp\Support\TransportFailed;

/**
 * Prévient un client final par WhatsApp (modèle approuvé par Meta, seul autorisé hors fenêtre de 24 h) quand son rendez-vous
 * est créé, déplacé ou annulé par l'établissement. Au mieux : un client sans numéro WhatsApp joignable, désinscrit (STOP) ou un échec
 * de Meta ne bloque jamais l'action de l'établissement ; l'appelant reçoit simplement `false` et peut prévenir le client autrement.
 *
 * Le modèle est celui, approuvé, du secteur de l'établissement (BookingMessageSender) ; jour et heure sont ceux du nouveau créneau pour un déplacement.
 */
final class BookingNotifier
{
    public function __construct(private readonly BookingMessageSender $sender) {}

    /** Confirmation d'un rendez-vous pris par le personnel. À exécuter sous le contexte de l'organisation, APRÈS l'écriture. */
    public function confirmed(Organization $organization, Booking $booking): bool
    {
        return $this->notify($organization, $booking, MessageTemplateType::CONFIRMATION);
    }

    /** Rendez-vous déplacé : le message donne le nouveau créneau. */
    public function moved(Organization $organization, Booking $booking): bool
    {
        return $this->notify($organization, $booking, MessageTemplateType::MODIFICATION);
    }

    public function cancelled(Organization $organization, Booking $booking): bool
    {
        return $this->notify($organization, $booking, MessageTemplateType::CANCELLATION);
    }

    /**
     * @param  string  $type  confirmation, modification ou cancellation (MessageTemplateType)
     */
    private function notify(Organization $organization, Booking $booking, string $type): bool
    {
        $customer = Customer::query()->find($booking->customer_id);

        if ($customer === null) {
            return false;
        }

        $language = in_array($customer->language, (array) config('nova.locales'), true) ? (string) $customer->language : (string) config('app.fallback_locale');
        try {
            $this->sender->send($organization, $booking, $customer, $type, $language);
        } catch (SendRefused|TransportFailed) {
            return false;
        }

        return true;
    }
}
