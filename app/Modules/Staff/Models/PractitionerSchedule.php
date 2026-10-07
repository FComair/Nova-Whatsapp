<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Plage de disponibilité hebdomadaire d'un praticien sur un site. weekday : 1 = lundi ... 7 = dimanche.
 *
 * @property string $id
 * @property string $org_id
 * @property string $practitioner_id
 * @property string $location_id
 * @property int $weekday
 * @property string $starts_at HH:MM:SS
 * @property string $ends_at HH:MM:SS
 */
class PractitionerSchedule extends Model
{
    use HasUuids;

    protected $guarded = [];
}
