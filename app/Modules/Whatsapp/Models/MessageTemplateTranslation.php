<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Texte d'un modèle dans une langue, avec ses variables nommées (`{{customer_name}}`).
 *
 * @property string $id
 * @property string $template_id
 * @property string $language
 * @property string $body
 */
class MessageTemplateTranslation extends Model
{
    use HasUuids;

    protected $table = 'message_template_translations';

    protected $guarded = [];

    /**
     * @return BelongsTo<MessageTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }
}
