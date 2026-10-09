<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Avis PRIVÉ d'un client sur Nova, vu par le Super Admin (lecture seule, toutes organisations). Ce n'est pas un avis Google ou
 * Trustpilot : les avis publics sont écrits par les clients sur ces plateformes et ne passent jamais par ici.
 *
 * @property string $id
 * @property int $rating
 * @property string|null $comment
 * @property string $source
 */
class PlatformFeedback extends Model
{
    use HasUuids;

    protected $connection = 'pgsql_platform';

    protected $table = 'nova_feedback';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rating' => 'integer', 'created_at' => 'datetime'];
    }
}
