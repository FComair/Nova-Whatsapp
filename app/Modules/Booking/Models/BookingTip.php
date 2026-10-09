<?php

declare(strict_types=1);

namespace App\Modules\Booking\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pourboire d'un rendez-vous (docs/24) : un montant à part du prix et du montant encaissé, réparti entre un ou plusieurs
 * praticiens. Un seul par rendez-vous.
 *
 * @property string $id
 * @property string $org_id
 * @property string $booking_id
 * @property string $customer_id
 * @property int $amount_cents
 * @property string $currency
 * @property Carbon $paid_at
 * @property string|null $recorded_by
 */
class BookingTip extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return HasMany<BookingTipAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(BookingTipAllocation::class, 'tip_id')->orderBy('created_at')->orderBy('id');
    }
}
