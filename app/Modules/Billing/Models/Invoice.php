<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Facture. Immuable une fois émise (seuls le statut, la date de paiement et la pseudonymisation évoluent).
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $subscription_id
 * @property string|null $plan_name formule facturée, figée à l'émission
 * @property string $number
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $status open | paid | void | uncollectible
 * @property string $currency
 * @property int $subtotal_cents
 * @property int $vat_rate
 * @property int $vat_cents
 * @property int $total_cents
 * @property array<string, mixed> $seller_snapshot
 * @property array<string, mixed> $billing_snapshot
 * @property Carbon $issued_at
 * @property Carbon|null $paid_at
 * @property Carbon|null $pseudonymised_at
 */
class Invoice extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'pseudonymised_at' => 'datetime',
            'seller_snapshot' => 'array',
            'billing_snapshot' => 'array',
        ];
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }
}
