<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Models;

use App\Modules\Whatsapp\Support\MessageTemplateStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Modèle de message WhatsApp de la bibliothèque, propre à UN secteur (docs/39). Vu par l'application cliente en lecture seule ;
 * écrit par le Super Admin (voir PlatformMessageTemplate).
 *
 * @property string $id
 * @property string $sector_code
 * @property string $type
 * @property string $slug
 * @property string $name
 * @property string $meta_name
 * @property string $category utility ou marketing
 * @property string $status draft, pending_approval, approved, rejected ou archived
 * @property bool $is_enabled
 * @property string|null $rejection_reason
 * @property string|null $created_by
 * @property string|null $decided_by
 * @property Carbon|null $submitted_at
 * @property Carbon|null $decided_at
 */
class MessageTemplate extends Model
{
    use HasUuids;

    protected $table = 'message_templates';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'submitted_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /**
     * @return HasMany<MessageTemplateTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(MessageTemplateTranslation::class, 'template_id');
    }

    /** Utilisable pour de vrais envois : approuvé ET activé. */
    public function usable(): bool
    {
        return $this->status === MessageTemplateStatus::APPROVED && $this->is_enabled;
    }
}
