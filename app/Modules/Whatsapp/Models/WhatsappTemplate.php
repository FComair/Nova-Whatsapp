<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Suivi, par établissement, d'un modèle de message soumis à Meta.
 *
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string $language
 * @property string $status pending, approved, rejected ou paused
 * @property string|null $rejection_reason
 * @property string|null $meta_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $synced_at
 */
class WhatsappTemplate extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'synced_at' => 'datetime'];
    }
}
