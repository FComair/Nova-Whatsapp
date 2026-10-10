<?php

declare(strict_types=1);

namespace App\Modules\Help\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Version enregistrée d'une traduction (ajout seul) : permet de relire et de rétablir un texte antérieur. Réservée à l'équipe.
 *
 * @property string $id
 * @property string $article_id
 * @property string $locale
 * @property string $question
 * @property string $summary
 * @property string $body
 * @property bool $is_ready
 * @property string|null $edited_by
 * @property Carbon $created_at
 */
class FaqVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $connection = 'pgsql_platform';

    protected $table = 'faq_translation_versions';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_ready' => 'boolean', 'created_at' => 'datetime'];
    }
}
