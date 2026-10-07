<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Models\Location;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plage d'ouverture hebdomadaire d'un site. weekday : 1 = lundi ... 7 = dimanche.
 *
 * @property string $id
 * @property string $org_id
 * @property string $location_id
 * @property int $weekday
 * @property string $starts_at HH:MM:SS
 * @property string $ends_at HH:MM:SS
 */
class BusinessHour extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
