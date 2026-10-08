<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $org_id
 * @property string $name
 * @property string|null $message
 * @property string $color
 * @property bool $show_logo
 * @property bool $is_default
 */
class WhatsappQrCode extends Model
{
    use HasUuids;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['show_logo' => 'boolean', 'is_default' => 'boolean'];
    }
}
