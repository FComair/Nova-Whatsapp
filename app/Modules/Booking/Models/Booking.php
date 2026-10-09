<?php

declare(strict_types=1);

namespace App\Modules\Booking\Models;

use App\Modules\Catalog\Casts\VatRate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Rendez-vous. Ne s'écrit QUE par le cœur de réservation (BookingCore) : jamais directement.
 *
 * @property string $id
 * @property string $org_id
 * @property string $location_id
 * @property string $customer_id
 * @property string $practitioner_id
 * @property string $service_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable $blocked_from
 * @property CarbonImmutable $blocked_until
 * @property string $status
 * @property string $source
 * @property int $price_cents
 * @property int|float $vat_rate pourcentage au moment de la réservation (21 ou 21.5)
 * @property string $payment_status
 * @property int $amount_collected_cents
 * @property string|null $notes_enc
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $reminder_sent_at
 * @property int $reminder_attempts
 */
class Booking extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED_BY_CUSTOMER = 'cancelled_by_customer';

    public const CANCELLED_BY_BUSINESS = 'cancelled_by_business';

    public const NO_SHOW = 'no_show';

    public const COMPLETED = 'completed';

    public const EXPIRED = 'expired';

    /** Statuts qui occupent le créneau (mêmes statuts que la contrainte d'exclusion de la base). */
    public const BLOCKING = [self::PENDING, self::CONFIRMED];

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'vat_rate' => VatRate::class,
            'ends_at' => 'immutable_datetime',
            'blocked_from' => 'immutable_datetime',
            'blocked_until' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'reminder_sent_at' => 'immutable_datetime',
            'notes_enc' => 'encrypted',
        ];
    }

    public function isBlocking(): bool
    {
        return in_array($this->status, self::BLOCKING, true);
    }
}
