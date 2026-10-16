<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use App\Modules\Booking\Models\Booking;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;

/**
 * Valeurs des variables des messages WhatsApp d'un rendez-vous (voir MessageVariables). Les dates et heures sont celles du fuseau du
 * prestataire (docs/04 §5). Une valeur inconnue reste vide : le résolveur la remplace par « - ».
 */
final class BookingMessageValues
{
    /**
     * @return array<string, string>
     */
    public static function for(Organization $organization, Booking $booking, Customer $customer, string $language): array
    {
        $location = Location::query()->whereKey($booking->location_id)->first();
        $local = $booking->starts_at->setTimezone($location instanceof Location && $location->timezone !== '' ? $location->timezone : 'UTC');
        $address = $location instanceof Location ? (array) $location->address : [];

        return [
            'business_name' => (string) $organization->name,
            'customer_name' => filled($customer->name) ? (string) $customer->name : (string) trans('whatsapp.customer_fallback', [], $language),
            'service_name' => (string) Service::query()->whereKey($booking->service_id)->value('name'),
            'practitioner_name' => (string) Practitioner::query()->whereKey($booking->practitioner_id)->value('name'),
            'date' => $local->locale($language)->isoFormat('dddd DD/MM/YYYY'),
            'time' => $local->format('H:i'),
            'address' => trim(implode(', ', array_filter([(string) ($address['street'] ?? ''), trim(($address['postal_code'] ?? '').' '.($address['city'] ?? ''))]))),
        ];
    }
}
