<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use App\Modules\Catalog\Models\Service;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $photo_updated_at dernier envoi de la photo de profil (sert de version du fichier)
 */
class Practitioner extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_INACTIVE = 'inactive';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['photo_updated_at' => 'datetime'];
    }

    /** Version de la photo pour l'adresse de l'image (null sans photo). */
    public function photoVersion(): ?int
    {
        return $this->photo_updated_at?->getTimestamp();
    }

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
