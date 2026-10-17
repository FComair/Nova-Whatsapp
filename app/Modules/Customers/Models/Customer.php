<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Client final d'une organisation, identifié par son numéro WhatsApp (E.164).
 *
 * @property string $id
 * @property string $org_id
 * @property string $phone_e164
 * @property string|null $name
 * @property string|null $email
 * @property string|null $language
 * @property list<string> $tags
 * @property bool $is_blocked
 * @property string $status active, archived ou blocked (is_blocked reste le miroir lu par le moteur de réservation)
 * @property int $reference_number numéro par établissement, attribué par la base
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $display_name
 * @property string|null $whatsapp_profile_name nom de profil WhatsApp : dernier recours, jamais le nom de la fiche
 * @property Carbon|null $date_of_birth
 * @property string|null $whatsapp_phone
 * @property array<string, mixed>|null $address
 * @property string|null $acquisition_source
 * @property string|null $assigned_practitioner_id
 * @property string|null $default_location_id
 * @property string|null $preferred_channel
 * @property array<string, mixed> $custom
 * @property Carbon|null $whatsapp_opted_out_at
 */
class Customer extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    protected static function booted(): void
    {
        // is_blocked (lu par le moteur de réservation) et status ne doivent jamais diverger : la base l'impose, le modèle les aligne.
        static::saving(function (Customer $customer): void {
            if ($customer->isDirty('is_blocked') && ! $customer->isDirty('status')) {
                $customer->status = $customer->is_blocked ? 'blocked' : ($customer->getOriginal('status') === 'blocked' ? 'active' : $customer->status);
            } elseif ($customer->isDirty('status')) {
                $customer->is_blocked = $customer->status === 'blocked';
            }
        });
    }

    /**
     * Nom enregistré dans la fiche (liste de clients) : le nom d'affichage s'il existe, sinon le nom, sinon prénom et nom. Jamais le profil WhatsApp.
     */
    public function listName(): ?string
    {
        foreach ([$this->display_name, $this->name, trim((string) $this->first_name.' '.(string) $this->last_name)] as $candidate) {
            $candidate = trim((string) $candidate);

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Nom à utiliser dans les conversations et messages WhatsApp (docs/41) :
     *  1. le nom saisi dans la plateforme (par le prestataire ou donné par le client) et 2. le nom de la liste de clients — qui sont la même fiche ;
     *  3. seulement à défaut, le nom de profil WhatsApp.
     */
    public function contactName(): ?string
    {
        $profile = trim((string) $this->whatsapp_profile_name);

        return $this->listName() ?? ($profile === '' ? null : $profile);
    }

    /** Référence lisible : C-1042. */
    public function reference(): string
    {
        return 'C-'.$this->reference_number;
    }

    /**
     * Change le statut en gardant is_blocked identique (une contrainte de la base l'exige).
     */
    public function setStatus(string $status): void
    {
        $this->forceFill(['status' => $status, 'is_blocked' => $status === 'blocked'])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_blocked' => 'boolean',
            'anonymized_at' => 'datetime',
            'whatsapp_opted_out_at' => 'datetime',
            'date_of_birth' => 'date',
            'address' => 'array',
            'custom' => 'array',
        ];
    }
}
