<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Participation d'un client au programme. `stamps` est le solde de tampons en cours : toujours égal à la somme du journal.
 *
 * @property string $id
 * @property string $org_id
 * @property string $program_id
 * @property string $customer_id
 * @property string $status active ou opted_out
 * @property int $stamps
 * @property int $lifetime_stamps
 * @property int $rewards_earned
 * @property Carbon|null $last_visit_at
 * @property string $joined_via auto ou staff
 * @property Carbon $joined_at
 * @property Carbon|null $opted_out_at
 */
final class LoyaltyMember extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const OPTED_OUT = 'opted_out';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_visit_at' => 'datetime', 'joined_at' => 'datetime', 'opted_out_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
