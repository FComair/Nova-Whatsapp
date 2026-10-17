<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Services;

use App\Modules\Audit\Audit;
use App\Modules\Booking\Models\Booking;
use App\Modules\Customers\Models\Customer;
use App\Modules\Loyalty\Services\LoyaltyEngine;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Effacement d'un client final à sa demande (RGPD art. 17, erreur E14).
 *
 * Sont supprimés : son nom, son e-mail, sa langue, ses étiquettes, ses conversations, les notes de ses rendez-vous et son numéro
 * (remplacé par un numéro fictif inutilisable). L'historique des rendez-vous (dates, services, prix) est conservé sans lien avec
 * une personne, pour les statistiques et la comptabilité. Refusé tant qu'il reste un rendez-vous à venir.
 */
final class CustomerErasure
{
    /**
     * @throws InvalidArgumentException `upcoming_bookings` quand des rendez-vous à venir existent
     */
    public function erase(Organization $organization, Customer $customer, ?string $actorId): void
    {
        $upcoming = Booking::query()->where('customer_id', $customer->id)->whereIn('status', Booking::BLOCKING)->where('starts_at', '>', now())->exists();

        if ($upcoming) {
            throw new InvalidArgumentException('upcoming_bookings');
        }

        DB::transaction(function () use ($organization, $customer, $actorId): void {
            WhatsappMessage::query()->where('customer_id', $customer->id)->delete();
            app(LoyaltyEngine::class)->customerErased($customer); // quitte le programme de fidélité, récompenses disponibles annulées
            Booking::query()->where('customer_id', $customer->id)->update(['notes_enc' => null]);

            $customer->forceFill([
                'name' => null,
                'email' => null,
                'language' => null,
                'tags' => [],
                'first_name' => null,
                'last_name' => null,
                'display_name' => null,
                'whatsapp_profile_name' => null,
                'date_of_birth' => null,
                'whatsapp_phone' => null,
                'address' => null,
                'acquisition_source' => null,
                'assigned_practitioner_id' => null,
                'default_location_id' => null,
                'preferred_channel' => null,
                'custom' => [],
                'status' => 'archived',
                'is_blocked' => false,
                'whatsapp_opted_out_at' => null,
                'phone_e164' => $this->placeholderPhone(),
                'anonymized_at' => now(),
                'deleted_at' => now(),
            ])->save();

            // Le journal ne garde ni nom ni numéro : seulement l'identifiant du client effacé.
            Audit::record('customer.erased', $actorId === null ? 'system' : 'user', $actorId, $organization->id, 'customers', $customer->id);
        });
    }

    /** Numéro E.164 valide mais inutilisable (indicatif +999 réservé, jamais attribué). */
    private function placeholderPhone(): string
    {
        do {
            $phone = '+999'.str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
        } while (Customer::withTrashed()->where('phone_e164', $phone)->exists());

        return $phone;
    }
}
