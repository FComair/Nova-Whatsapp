<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Historique d'un ticket (ajout seul) : création, changements d'état, réouverture, clôture.
 *
 * @property string $id
 * @property string $org_id
 * @property string $ticket_id
 * @property string $actor_type client, staff ou system
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string|null $note
 * @property Carbon $created_at
 */
final class SupportTicketEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'support_ticket_events';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
