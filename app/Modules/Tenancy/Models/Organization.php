<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Agrégat racine multi-tenant. Le statut suit la machine à états de docs/01-schema-base-de-donnees.md §12.
 *
 * @property string $id
 * @property string $name
 * @property string $status
 * @property string|null $sector
 * @property string $locale
 * @property string $country
 * @property string $timezone
 * @property string|null $plan_id
 * @property string|null $signup_plan
 * @property string|null $suspension_reason
 * @property Carbon|null $suspended_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $pause_until
 * @property Carbon|null $pause_reminder_sent_at
 * @property string|null $deletion_confirm_token_hash
 * @property Carbon|null $deletion_confirm_expires_at
 * @property array<string, string>|null $unavailable_message
 * @property Carbon|null $deletion_requested_at
 * @property Carbon|null $deletion_scheduled_for
 * @property Carbon|null $deletion_started_at
 * @property string|null $previous_status
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $trial_reminder_sent_at
 * @property string|null $billing_name
 * @property string|null $billing_vat_number
 * @property array<string, mixed>|null $billing_address
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuids;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_PENDING_DELETION = 'pending_deletion';

    public const STATUS_DELETED = 'deleted';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'terminology' => 'array',
            'unavailable_message' => 'array',
            'billing_address' => 'array',
            'data_sensitivity' => 'boolean',
            'trial_ends_at' => 'datetime',
            'paused_at' => 'datetime',
            'pause_until' => 'datetime',
            'pause_reminder_sent_at' => 'datetime',
            'deletion_confirm_expires_at' => 'datetime',
            'suspended_at' => 'datetime',
            'suspension_ends_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'deletion_scheduled_for' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Lecture seule : tant que le compte est suspendu ou en attente de suppression, aucune écriture métier.
     */
    public function isReadOnly(): bool
    {
        return in_array($this->status, [self::STATUS_SUSPENDED, self::STATUS_PENDING_DELETION, self::STATUS_DELETED], true);
    }

    /**
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class, 'org_id');
    }

    /**
     * @return HasMany<OrgMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(OrgMember::class, 'org_id');
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
