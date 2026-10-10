<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Modules\Support\Support\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Demande d'un client à l'équipe Nova (docs/22, docs/29). La référence « NWS-1248 » vient d'une séquence de la base.
 *
 * @property string $id
 * @property string $org_id
 * @property int $reference_number
 * @property string $user_id auteur
 * @property string|null $submission_token
 * @property string $reply_email
 * @property string $locale
 * @property string $subject
 * @property string $category
 * @property string|null $related_to
 * @property string $urgency priorité choisie par le client
 * @property string|null $staff_priority priorité fixée par l'équipe (prioritaire sur l'urgence du client)
 * @property string $status
 * @property string $source
 * @property string|null $assigned_admin_id
 * @property Carbon $last_message_at
 * @property Carbon|null $last_staff_message_at
 * @property Carbon|null $last_client_message_at
 * @property Carbon|null $client_read_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $first_response_due_at
 * @property string|null $sla_alert
 * @property int $version
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property string|null $closed_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SupportTicket extends Model
{
    use HasUuids;

    public const CATEGORIES = ['technical', 'feature', 'billing', 'access', 'other'];

    public const RELATED = ['appointments', 'whatsapp', 'customers', 'services', 'planning', 'other'];

    public const URGENCIES = ['low', 'medium', 'high'];

    /** Priorités de l'équipe, de la plus faible à la plus forte (« urgent » n'est pas proposé au client). */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public const SOURCES = ['contact_form', 'email', 'in_app', 'manual'];

    protected $table = 'support_tickets';

    protected $guarded = [];

    protected static function booted(): void
    {
        // Chaque modification incrémente la version : deux modifications simultanées se détectent (docs/29 §6).
        static::updating(function (self $ticket): void {
            if (! $ticket->isDirty('version')) {
                $ticket->version = (int) $ticket->getOriginal('version') + 1;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reference_number' => 'integer',
            'version' => 'integer',
            'last_message_at' => 'datetime',
            'last_staff_message_at' => 'datetime',
            'last_client_message_at' => 'datetime',
            'client_read_at' => 'datetime',
            'first_response_at' => 'datetime',
            'first_response_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** « NWS-1248 ». */
    public function reference(): string
    {
        return 'NWS-'.$this->reference_number;
    }

    /** Priorité effective : celle de l'équipe si elle existe, sinon l'urgence indiquée par le client. */
    public function priority(): string
    {
        return $this->staff_priority ?? $this->urgency;
    }

    /**
     * @return HasMany<SupportMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    /**
     * @return HasMany<SupportTicketEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id');
    }

    /**
     * Tickets qu'une personne a le droit de voir : les siens ; le propriétaire voit aussi ceux de son établissement.
     *
     * @param  Builder<SupportTicket>  $query
     * @return Builder<SupportTicket>
     */
    public function scopeVisibleTo(Builder $query, string $userId, bool $everything): Builder
    {
        return $everything ? $query : $query->where('support_tickets.user_id', $userId);
    }

    /** Lit « NWS-1248 », « nws-1248 » ou « 1248 ». */
    public static function numberFrom(string $reference): ?int
    {
        return preg_match('/^(?:nws-?)?(\d{1,12})$/i', trim($reference), $match) === 1 ? (int) $match[1] : null;
    }

    /** Une réponse de l'équipe attend le client (non lue), ou l'équipe attend sa réponse. */
    public function needsAttention(): bool
    {
        if ($this->status === TicketStatus::CLOSED) {
            return false;
        }

        return $this->status === TicketStatus::WAITING_ON_YOU
            || ($this->last_staff_message_at !== null && ($this->client_read_at === null || $this->client_read_at < $this->last_staff_message_at));
    }

    /** Le dernier mot est au client : l'équipe doit répondre (ticket non résolu, non fermé). */
    public function needsResponse(): bool
    {
        if (! TicketStatus::isOpenWork($this->status) || $this->last_client_message_at === null) {
            return false;
        }

        return $this->last_staff_message_at === null || $this->last_client_message_at > $this->last_staff_message_at;
    }
}
