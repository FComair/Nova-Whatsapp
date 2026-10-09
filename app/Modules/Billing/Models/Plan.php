<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Offre commerciale (Basic, Business). Table globale en lecture seule pour l'application cliente.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int $price_monthly_cents
 * @property string $currency
 * @property array<string, int|null> $limits
 * @property bool $is_recommended désignée explicitement par la plateforme (au plus une)
 */
class Plan extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['limits' => 'array', 'is_active' => 'boolean', 'is_recommended' => 'boolean'];
    }
}
