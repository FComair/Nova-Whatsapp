<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use App\Modules\Catalog\Models\Service;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Praticien (ou employé) qui reçoit les rendez-vous. `draft` : ajouté au-delà de la limite du plan, non réservable.
 *
 * @property string $id
 * @property string $org_id
 * @property string|null $user_id
 * @property string $name
 * @property string|null $bio
 * @property string $color
 * @property string $status
 * @property int $position
 */
class Practitioner extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_INACTIVE = 'inactive';

    protected $guarded = [];

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'practitioner_services')
            ->withPivot(['org_id', 'custom_duration_minutes', 'custom_price_cents']);
    }

    /**
     * @return HasMany<PractitionerSchedule, $this>
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(PractitionerSchedule::class);
    }
}
