<?php

declare(strict_types=1);

namespace App\Modules\Api\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Client de l'API interne. Périmètres (scopes) : availability:read, bookings:read, bookings:write, customers:write, conversations:write, config:read.
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string $key_id
 * @property string $secret_enc
 * @property list<string> $scopes
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 */
class ApiClient extends Model
{
    use HasUuids;

    public const SCOPES = ['availability:read', 'bookings:read', 'bookings:write', 'customers:write', 'conversations:write', 'config:read'];

    protected $guarded = [];

    protected $hidden = ['secret_enc'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['scopes' => 'array', 'secret_enc' => 'encrypted', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
