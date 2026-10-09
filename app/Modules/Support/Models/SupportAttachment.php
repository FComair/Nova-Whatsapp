<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Pièce jointe d'un message : le fichier est sur le disque privé, on ne le sert que par une route qui vérifie le droit d'accès.
 *
 * @property string $id
 * @property string $org_id
 * @property string $message_id
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string $path
 */
final class SupportAttachment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'support_attachments';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
