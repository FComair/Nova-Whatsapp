<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Organization;
use InvalidArgumentException;

/**
 * Retrouve ou crée un client par son numéro (E.164). Le numéro doit déjà être normalisé par l'appelant.
 */
final class CustomerDirectory
{
    public function findOrCreate(Organization $organization, string $phoneE164, ?string $name = null, ?string $language = null): Customer
    {
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $phoneE164)) {
            throw new InvalidArgumentException('Numéro de téléphone invalide (format E.164 attendu).');
        }

        $customer = Customer::query()->where('phone_e164', $phoneE164)->first();

        if ($customer !== null) {
            // Complète les informations manquantes sans écraser ce que le commerce a saisi.
            $customer->fill(array_filter([
                'name' => $customer->name === null ? $name : null,
                'language' => $customer->language === null ? $language : null,
            ]));
            $customer->save();

            return $customer;
        }

        return Customer::query()->create([
            'org_id' => $organization->id,
            'phone_e164' => $phoneE164,
            'name' => $name !== null ? trim($name) : null,
            'language' => $language,
        ]);
    }
}
