<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Entrée du journal en ajout seul d'un modèle : qui a fait quoi, de quel état à quel état, avec quel motif.
 *
 * @property string $id
 * @property string $template_id
 * @property string $sector_code
 * @property string $template_name
 * @property string $action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $reason
 * @property string|null $actor_id
 * @property array<string, mixed>|null $snapshot
 * @property Carbon $created_at
 */
class MessageTemplateEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'message_template_events';

    protected $connection = 'pgsql_platform';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'created_at' => 'datetime'];
    }
}
