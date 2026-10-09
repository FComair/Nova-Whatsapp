<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Casts\VatRate;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Service réservable. Montants en centimes, durée et marges en minutes.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $category_id
 * @property string $name
 * @property string|null $description
 * @property int $duration_minutes
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property int $price_cents
 * @property int|float $vat_rate pourcentage (21 ou 21.5)
 * @property int $capacity
 * @property bool $bookable_online
 * @property bool $notes_required
 * @property bool $is_active
 * @property int $position
 */
class Service extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bookable_online' => 'boolean',
            'notes_required' => 'boolean',
            'is_active' => 'boolean',
            'vat_rate' => VatRate::class,
        ];
    }

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }
}
