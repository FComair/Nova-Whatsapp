<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Abonnement d'une organisation (un seul). La descente de formule est appliquée à l'échéance (`pending_plan_id`).
 *
 * @property string $id
 * @property string $org_id
 * @property string $plan_id
 * @property string|null $pending_plan_id
 * @property string $status active | past_due | canceled
 * @property string $provider
 * @property string|null $payment_method_id
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property Carbon|null $cancel_at
 * @property int $failed_attempts
 * @property Carbon|null $next_attempt_at
 */
class Subscription extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['current_period_start' => 'datetime', 'current_period_end' => 'datetime', 'cancel_at' => 'datetime', 'next_attempt_at' => 'datetime'];
    }
}
