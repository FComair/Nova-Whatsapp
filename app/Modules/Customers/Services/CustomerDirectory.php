<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Audit\Audit;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Str;
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

    /**
     * Client qui écrit depuis WhatsApp : retrouvé par son numéro, ou créé SANS nom. Le nom de profil WhatsApp est gardé à part
     * (`whatsapp_profile_name`) et ne devient jamais le nom de la fiche : il ne sert que de dernier recours (Customer::contactName).
     *
     * Résolution du numéro (docs/41) : d'abord `phone_e164` (unique par établissement), puis `whatsapp_phone` (numéro WhatsApp distinct
     * saisi dans la fiche). Si les deux désignent des fiches différentes, celle dont `phone_e164` correspond l'emporte et le doublon est tracé.
     */
    public function findOrCreateFromWhatsapp(Organization $organization, string $phoneE164, ?string $profileName = null, ?string $language = null): Customer
    {
        if (! preg_match('/^\+[1-9]\d{7,14}$/', $phoneE164)) {
            throw new InvalidArgumentException('Numéro de téléphone invalide (format E.164 attendu).');
        }

        $customer = $this->resolveByNumber($organization, $phoneE164) ?? Customer::query()->create([
            'org_id' => $organization->id,
            'phone_e164' => $phoneE164,
            'name' => null,
            'language' => $language,
        ]);

        $profile = $profileName === null ? null : Str::limit(trim($profileName), 120, '');
        $profile = $profile === '' ? null : $profile;

        if ($profile !== null && $customer->whatsapp_profile_name !== $profile) {
            $customer->forceFill(['whatsapp_profile_name' => $profile])->save();
        }

        return $customer;
    }

    private function resolveByNumber(Organization $organization, string $phoneE164): ?Customer
    {
        $byPhone = Customer::query()->where('phone_e164', $phoneE164)->first();
        $others = Customer::query()->where('whatsapp_phone', $phoneE164)->when($byPhone !== null, fn ($query) => $query->where('id', '<>', $byPhone?->id))->orderBy('created_at')->get();

        if ($byPhone !== null) {
            $others->isNotEmpty() && $this->flagAmbiguous($organization, $byPhone, $others->pluck('id')->all());

            return $byPhone;
        }

        if ($others->count() > 1) {
            $this->flagAmbiguous($organization, $others->first(), $others->skip(1)->pluck('id')->all());
        }

        return $others->first();
    }

    /**
     * Le même numéro désigne plusieurs fiches de l'établissement : on trace pour que le prestataire les fusionne.
     *
     * @param  list<string>  $otherIds
     */
    private function flagAmbiguous(Organization $organization, ?Customer $chosen, array $otherIds): void
    {
        if ($chosen === null) {
            return;
        }

        Audit::record('customer.number_ambiguous', 'system', null, $organization->id, 'customers', $chosen->id, ['chosen' => $chosen->id, 'others' => $otherIds]);
    }
}
