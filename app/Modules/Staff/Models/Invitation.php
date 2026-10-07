<?php

declare(strict_types=1);

namespace App\Modules\Staff\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Invitation d'un praticien. Le jeton n'existe qu'en clair dans l'e-mail : seule son empreinte SHA-256 est stockée.
 *
 * @property string $id
 * @property string $org_id
 * @property string $practitioner_id
 * @property string $email
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 */
class Invitation extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Utilisable : ni acceptée, ni révoquée, ni expirée. */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->used_at === null && $this->revoked_at === null && $this->expires_at->isPast();
    }
}
