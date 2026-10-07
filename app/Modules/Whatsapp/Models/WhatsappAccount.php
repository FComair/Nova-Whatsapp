<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $org_id
 * @property string $phone_number_id
 * @property string|null $display_phone
 * @property string $access_token_enc
 * @property string $status
 * @property string|null $waba_id
 */
class WhatsappAccount extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['access_token_enc'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['access_token_enc' => 'encrypted'];
    }
}
