<?php

declare(strict_types=1);

namespace App\Modules\Booking\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Événement d'une réservation, en ajout seul (jamais de UPDATE ni de DELETE, même par le propriétaire de la base).
 *
 * @property string $id
 * @property string $booking_id
 * @property string $action
 * @property array<string, mixed>|null $diff
 */
class BookingEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['diff' => 'array'];
    }
}
