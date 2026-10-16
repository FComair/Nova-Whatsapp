<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Services\MessageSender;
use App\Modules\Whatsapp\Services\SectorTemplateResolver;
use App\Modules\Whatsapp\Services\WhatsappUsage;
use App\Modules\Whatsapp\Support\MessageTemplateType;
use App\Modules\Whatsapp\Support\SendRefused;
use App\Modules\Whatsapp\Support\TransportFailed;

/**
 * Envoie le message WhatsApp d'un rendez-vous (confirmation, rappel, déplacement, annulation) avec le modèle APPROUVÉ du secteur de
 * l'établissement (docs/39). Tant qu'aucun modèle du secteur n'est approuvé pour ce déclencheur, l'ancien modèle global reprend la main
 * (`whatsapp.catalog.legacy_fallback`, actif par défaut) ; désactivé, rien ne part sans modèle approuvé (`SendRefused::NO_TEMPLATE`).
 */
final class BookingMessageSender
{
    public function __construct(private readonly MessageSender $sender, private readonly SectorTemplateResolver $resolver) {}

    /**
     * @param  string  $type  un type automatique de MessageTemplateType (confirmation, reminder, modification, cancellation)
     *
     * @throws SendRefused
     * @throws TransportFailed
     */
    public function send(Organization $organization, Booking $booking, Customer $customer, string $type, string $language): void
    {
        $values = BookingMessageValues::for($organization, $booking, $customer, $language);
        $resolved = $this->resolver->resolve($organization->sector, $type, $language, $values);

        if ($resolved !== null) {
            $this->sender->sendTemplate(
                $organization,
                $customer,
                $resolved->name,
                $resolved->language,
                $resolved->parameters,
                $resolved->category === 'marketing' ? WhatsappUsage::MARKETING : WhatsappUsage::UTILITY,
            );

            return;
        }

        if (! (bool) config('whatsapp.catalog.legacy_fallback', true)) {
            throw new SendRefused(SendRefused::NO_TEMPLATE);
        }

        $legacy = MessageTemplateType::AUTOMATIC[$type] ?? throw new SendRefused(SendRefused::NO_TEMPLATE);

        $this->sender->sendTemplate($organization, $customer, (string) config("whatsapp.templates.{$legacy}"), $language, [
            $values['business_name'], $values['service_name'], $values['date'], $values['time'],
        ]);
    }
}
