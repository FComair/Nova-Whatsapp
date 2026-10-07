<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Calendrier externe d'un praticien (lecture seule). L'adresse et les identifiants sont chiffrés au repos.
 *
 * @property string $id
 * @property string $org_id
 * @property string $practitioner_id
 * @property string $name
 * @property string $kind ics | caldav
 * @property string $url_enc
 * @property string|null $username_enc
 * @property string|null $password_enc
 * @property string $status active | error | paused
 * @property Carbon|null $last_synced_at
 * @property int $failure_count
 * @property string|null $last_error
 * @property int $unsupported_rules
 */
class ExternalCalendar extends Model
{
    use HasUuids;

    public const ICS = 'ics';

    public const CALDAV = 'caldav';

    protected $guarded = [];

    protected $hidden = ['url_enc', 'username_enc', 'password_enc'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'url_enc' => 'encrypted',
            'username_enc' => 'encrypted',
            'password_enc' => 'encrypted',
            'last_synced_at' => 'datetime',
        ];
    }
}
