<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Message d'une conversation de support (ajout seul). Les notes internes ne sont lisibles que par l'équipe (règle de la base).
 *
 * @property string $id
 * @property string $org_id
 * @property string $ticket_id
 * @property string $author_type client, staff ou system
 * @property string|null $author_id
 * @property string $visibility public ou internal
 * @property string $body
 * @property Carbon $created_at
 */
final class SupportMessage extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'support_messages';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /**
     * @return HasMany<SupportAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }
}
