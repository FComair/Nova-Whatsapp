<?php

declare(strict_types=1);

namespace App\Modules\Booking\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Part d'un pourboire revenant à un praticien. La somme des parts d'un pourboire est égale au pourboire (garanti par la base).
 *
 * @property string $id
 * @property string $tip_id
 * @property string $booking_id
 * @property string $practitioner_id
 * @property int $amount_cents
 */
class BookingTipAllocation extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }
}
