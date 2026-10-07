<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Moyen de paiement : jeton du prestataire (chiffré) et de quoi le reconnaître. Aucune donnée de carte.
 *
 * @property string $id
 * @property string $org_id
 * @property string $provider
 * @property string $provider_ref_enc jeton en clair (déchiffré par le cast)
 * @property string|null $brand
 * @property string|null $last4
 * @property int|null $exp_month
 * @property int|null $exp_year
 */
class PaymentMethod extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['provider_ref_enc'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['provider_ref_enc' => 'encrypted'];
    }
}
