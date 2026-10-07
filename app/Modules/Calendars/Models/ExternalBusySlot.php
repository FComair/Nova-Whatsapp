<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Plage occupée lue dans un calendrier externe : des horaires, jamais le contenu de l'événement.
 *
 * @property string $id
 * @property string $org_id
 * @property string $calendar_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class ExternalBusySlot extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
