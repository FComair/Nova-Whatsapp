<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Lien interne d'un ticket vers un autre ticket ou vers un enregistrement (rendez-vous, client, service, conversation WhatsApp).
 * Table réservée à l'équipe (rôle nova_platform).
 *
 * @property string $id
 * @property string $org_id
 * @property string $ticket_id
 * @property string $kind ticket, booking, customer, service, conversation ou other
 * @property string|null $ref_id identifiant de l'enregistrement lié, quand il existe
 * @property string $label
 * @property string|null $created_by
 * @property Carbon $created_at
 */
final class SupportTicketLink extends Model
{
    use HasUuids;

    public const KINDS = ['ticket', 'booking', 'customer', 'service', 'conversation', 'other'];

    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'support_ticket_links';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
