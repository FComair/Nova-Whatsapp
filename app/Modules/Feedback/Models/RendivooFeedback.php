<?php

declare(strict_types=1);

namespace App\Modules\Feedback\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Avis PRIVÉ d'un client à l'équipe Rendivoo (docs/26) : une note de 1 à 5 et un commentaire facultatif. Jamais publié, jamais présenté
 * comme un avis Google ou Trustpilot. Journal en ajout seul.
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string|null $ticket_id
 * @property string $source general, intervention ou ticket
 * @property int $rating
 * @property string|null $comment
 * @property string $locale
 * @property Carbon $created_at
 */
final class RendivooFeedback extends Model
{
    use HasUuids;

    public const SOURCES = ['general', 'intervention', 'ticket'];

    protected $table = 'nova_feedback';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rating' => 'integer', 'created_at' => 'datetime'];
    }
}
