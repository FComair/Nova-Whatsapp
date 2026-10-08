<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un consentement donné ou retiré (journal en ajout seul). L'état actuel est la dernière ligne par finalité et canal.
 *
 * @property string $id
 * @property string $org_id
 * @property string $customer_id
 * @property string $purpose appointment ou marketing
 * @property string $channel whatsapp, email ou phone
 * @property string $status granted ou withdrawn
 * @property string $source qr, conversation, form, staff, stop_reply, start_reply, import ou system
 * @property string|null $evidence_ref
 * @property string|null $recorded_by
 * @property Carbon $recorded_at
 */
class CustomerConsent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }
}
