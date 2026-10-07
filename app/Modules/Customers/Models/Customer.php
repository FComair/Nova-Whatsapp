<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Client final d'une organisation, identifié par son numéro WhatsApp (E.164).
 *
 * @property string $id
 * @property string $org_id
 * @property string $phone_e164
 * @property string|null $name
 * @property string|null $email
 * @property string|null $language
 * @property list<string> $tags
 * @property bool $is_blocked
 * @property Carbon|null $whatsapp_opted_out_at
 */
class Customer extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tags' => 'array', 'is_blocked' => 'boolean', 'anonymized_at' => 'datetime', 'whatsapp_opted_out_at' => 'datetime'];
    }
}
