<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Récompense obtenue par un client, avec l'instantané de ce qui lui a été promis.
 *
 * @property string $id
 * @property string $org_id
 * @property string $program_id
 * @property string $customer_id
 * @property string $status available, redeemed, expired ou void
 * @property array<string, mixed> $snapshot
 * @property string|null $earned_booking_id
 * @property Carbon $earned_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $expiry_reminded_at
 * @property Carbon|null $redeemed_at
 * @property string|null $redeemed_by
 * @property string|null $redeemed_booking_id
 * @property int|null $applied_value_cents
 */
final class LoyaltyReward extends Model
{
    use HasUuids;

    public const AVAILABLE = 'available';

    public const REDEEMED = 'redeemed';

    public const EXPIRED = 'expired';

    public const VOID = 'void';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'earned_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_reminded_at' => 'datetime',
            'redeemed_at' => 'datetime',
        ];
    }

    /** Disponible et non expirée à cet instant. */
    public function isUsable(): bool
    {
        return $this->status === self::AVAILABLE && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** Description de la récompense dans la langue demandée, d'après l'instantané. */
    public function description(string $locale): string
    {
        /** @var array<string, string> $values */
        $values = (array) ($this->snapshot['description'] ?? []);

        foreach ([$locale, 'fr', ...array_keys($values)] as $candidate) {
            $value = trim((string) ($values[$candidate] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
