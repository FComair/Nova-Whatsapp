<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Exception aux horaires d'ouverture d'un site pour une date : fermeture, ou plages exceptionnelles qui REMPLACENT les horaires habituels.
 *
 * @property string $location_id
 * @property Carbon $date
 * @property bool $is_closed
 * @property string|null $starts_at HH:MM:SS
 * @property string|null $ends_at HH:MM:SS
 */
class BusinessHourException extends Model
{
    use HasUuids;

    protected $table = 'business_hours_exceptions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_closed' => 'boolean', 'date' => 'date:Y-m-d'];
    }
}
