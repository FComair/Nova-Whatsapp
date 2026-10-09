<?php

declare(strict_types=1);

namespace App\Modules\Help\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Réponse à « Cet article vous a-t-il aidé ? » (journal en ajout seul, une réponse par personne, article et jour).
 *
 * @property string $id
 * @property string $org_id
 * @property string $user_id
 * @property string $locale
 * @property string $category
 * @property string $slug
 * @property bool $helpful
 * @property string|null $comment
 */
final class HelpFeedback extends Model
{
    use HasUuids;

    protected $table = 'help_article_feedback';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['helpful' => 'boolean'];
    }
}
