<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Message d'une conversation WhatsApp (entrant `in`, sortant `out`). Le texte est chiffré au repos.
 *
 * @property string $id
 * @property string $org_id
 * @property string $customer_id
 * @property string $direction
 * @property string|null $wa_message_id
 * @property string $type
 * @property string|null $body_enc
 * @property string $status
 * @property string|null $error_code
 * @property Carbon $created_at
 */
class WhatsappMessage extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['body_enc' => 'encrypted'];
    }
}
