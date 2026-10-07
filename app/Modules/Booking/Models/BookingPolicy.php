<?php

declare(strict_types=1);

namespace App\Modules\Booking\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Politiques de réservation de l'organisation. Une seule ligne par organisation, créée à la demande avec les valeurs par défaut.
 *
 * @property string $org_id
 * @property int $min_notice_hours
 * @property int $max_horizon_days
 * @property int $cancellation_window_hours
 * @property int $no_show_fee_cents
 * @property bool $allow_overbooking
 * @property int $hold_minutes
 * @property int $reminder_hours_before
 */
class BookingPolicy extends Model
{
    protected $primaryKey = 'org_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Valeurs par défaut proposées à un nouveau client (cohérentes avec les colonnes de la table).
     *
     * @var array<string, int|bool>
     */
    public const DEFAULTS = [
        'min_notice_hours' => 2,
        'max_horizon_days' => 60,
        'cancellation_window_hours' => 24,
        'no_show_fee_cents' => 0,
        'allow_overbooking' => false,
        'hold_minutes' => 10,
        'reminder_hours_before' => 24,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['allow_overbooking' => 'boolean'];
    }
}
