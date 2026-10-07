<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Tentative de paiement d'une facture. Le code d'échec vient du prestataire ; aucun numéro de carte.
 *
 * @property string $id
 * @property string $org_id
 * @property string $invoice_id
 * @property string $provider
 * @property string|null $provider_ref
 * @property string $status succeeded | failed
 * @property int $amount_cents
 * @property string|null $failure_code
 */
class Payment extends Model
{
    use HasUuids;

    protected $guarded = [];
}
