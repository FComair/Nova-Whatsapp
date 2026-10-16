<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Services\MessageSender;
use App\Modules\Whatsapp\Support\SendRefused;
use App\Modules\Whatsapp\Support\TransportFailed;

/**
 * Prévient un client final par WhatsApp (modèle approuvé par Meta, seul autorisé hors fenêtre de 24 h) quand son rendez-vous
 * est créé, déplacé ou annulé par l'établissement. Au mieux : un client sans numéro WhatsApp joignable, désinscrit (STOP) ou un échec
 * de Meta ne bloque jamais l'action de l'établissement ; l'appelant reçoit simplement `false` et peut prévenir le client autrement.
 *
 * Chaque modèle reçoit les mêmes quatre paramètres : établissement, service, jour et heure du rendez-vous (nouveau créneau pour un déplacement).
 */
final class BookingNotifier
{
    public function __construct(private readonly MessageSender $sender) {}

    /** Confirmation d'un rendez-vous pris par le personnel. À exécuter sous le contexte de l'organisation, APRÈS l'écriture. */
    public function confirmed(Organization $organization, Booking $booking): bool
    {
        return $this->notify($organization, $booking, 'confirmation');
    }

    /** Rendez-vous déplacé : le message donne le nouveau créneau. */
    public function moved(Organization $organization, Booking $booking): bool
    {
        return $this->notify($organization, $booking, 'rescheduled');
    }

    public function cancelled(Organization $organization, Booking $booking): bool
    {
        return $this->notify($organization, $booking, 'cancellation');
    }

    /**
     * @param  'confirmation'|'rescheduled'|'cancellation'  $template  clé de `config('whatsapp.templates')`
     */
    private function notify(Organization $organization, Booking $booking, string $template): bool
    {
        $customer = Customer::query()->find($booking->customer_id);

        if ($customer === null) {
            return false;
        }

        $language = in_array($customer->language, (array) config('nova.locales'), true) ? (string) $customer->language : (string) config('app.fallback_locale');
        $timezone = (string) Location::query()->whereKey($booking->location_id)->value('timezone');
        $local = $booking->starts_at->setTimezone($timezone ?: 'UTC');

        try {
            $this->sender->sendTemplate($organization, $customer, (string) config("whatsapp.templates.{$template}"), $language, [
                $organization->name,
                (string) Service::query()->whereKey($booking->service_id)->value('name'),
                $local->locale($language)->isoFormat('dddd DD/MM/YYYY'),
                $local->format('H:i'),
            ]);
        } catch (SendRefused|TransportFailed) {
            return false;
        }

        return true;
    }
}
