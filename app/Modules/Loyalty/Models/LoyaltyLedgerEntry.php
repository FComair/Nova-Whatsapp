<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ligne du journal de fidélité, en ajout seul : tampons gagnés ou annulés, ajustements, récompenses, changements de configuration.
 * Le solde d'un client est la somme de `stamps_delta` de ses lignes.
 *
 * @property string $id
 * @property string $org_id
 * @property string $program_id
 * @property string|null $customer_id
 * @property string $kind
 * @property int $stamps_delta
 * @property int|null $balance_after
 * @property string|null $booking_id
 * @property string|null $reward_id
 * @property string $actor_type user ou system
 * @property string|null $actor_id
 * @property string|null $reason
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 */
final class LoyaltyLedgerEntry extends Model
{
    use HasUuids;

    public const STAMP_EARNED = 'stamp_earned';

    public const STAMP_REVERSED = 'stamp_reversed';

    public const MANUAL = 'manual_adjustment';

    public const REWARD_ISSUED = 'reward_issued';

    public const REWARD_VOIDED = 'reward_voided';

    public const REWARD_REDEEMED = 'reward_redeemed';

    public const REDEMPTION_REVERSED = 'redemption_reversed';

    public const REWARD_EXPIRED = 'reward_expired';

    public const ENROLLED = 'enrolled';

    public const OPTED_OUT = 'opted_out';

    public const REJOINED = 'rejoined';

    public const CONFIG_CHANGED = 'config_changed';

    public const STATUS_CHANGED = 'status_changed';

    public const MESSAGE_SENT = 'message_sent';

    protected $table = 'loyalty_ledger';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
