<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Whatsapp\Models\MessageTemplate;
use App\Modules\Whatsapp\Models\MessageTemplateEvent;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Modèle de la bibliothèque WhatsApp, écrit avec le rôle `nova_platform` (Super Admin).
 *
 * @property int|null $translations_count
 */
class PlatformMessageTemplate extends MessageTemplate
{
    protected $connection = 'pgsql_platform';

    /**
     * @return HasMany<MessageTemplateEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(MessageTemplateEvent::class, 'template_id')->orderByDesc('created_at');
    }
}
