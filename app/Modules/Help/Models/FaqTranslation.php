<?php

declare(strict_types=1);

namespace App\Modules\Help\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une langue d'un article : question, résumé et texte (Markdown). « Prête » = relue et publiable ; une traduction non prête ne
 * quitte jamais le Super Admin.
 *
 * @property string $id
 * @property string $article_id
 * @property string $locale
 * @property string $question
 * @property string $summary
 * @property string $body
 * @property bool $is_ready
 * @property string|null $updated_by
 * @property Carbon $updated_at
 */
class FaqTranslation extends Model
{
    use HasUuids;

    protected $table = 'faq_translations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_ready' => 'boolean'];
    }

    /** Question, résumé et texte tous renseignés. */
    public function isComplete(): bool
    {
        return trim($this->question) !== '' && trim($this->summary) !== '' && trim($this->body) !== '';
    }
}
