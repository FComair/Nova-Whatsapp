<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property array<string, string|null>|null $address
 * @property string $timezone
 * @property bool $is_default
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'is_default' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }
}
