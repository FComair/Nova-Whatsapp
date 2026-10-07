<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Indisponibilité (`unavailable`) ou disponibilité exceptionnelle (`extra`) d'un praticien, en UTC.
 *
 * @property string $practitioner_id
 * @property string $kind
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 */
class PractitionerTimeOff extends Model
{
    use HasUuids;

    public const UNAVAILABLE = 'unavailable';

    public const EXTRA = 'extra';

    protected $table = 'practitioner_time_off';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
