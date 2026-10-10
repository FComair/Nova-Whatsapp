<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Étiquette interne d'un ticket. Table réservée à l'équipe (rôle nova_platform) : le client ne la lit jamais.
 *
 * @property string $id
 * @property string $org_id
 * @property string $ticket_id
 * @property string $tag
 * @property string|null $created_by
 * @property Carbon $created_at
 */
final class SupportTicketTag extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'support_ticket_tags';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
