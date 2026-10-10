<?php

declare(strict_types=1);

namespace App\Modules\Help\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Article d'aide (FAQ) géré par le Super Admin (docs/30) : une fiche, ses traductions (une par langue, jamais mélangées), sa catégorie,
 * ses étiquettes et ses articles liés. États : draft (invisible du client), published, archived (conservé pour l'historique).
 *
 * @property string $id
 * @property string $category_id
 * @property string $slug
 * @property string $status
 * @property string $publication all (publié dans toutes les langues, trois traductions prêtes) ou available (langues prêtes seulement)
 * @property int $position
 * @property bool $is_popular
 * @property list<string> $tags
 * @property string|null $author_id
 * @property string|null $last_editor_id
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FaqArticle extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::ARCHIVED];

    public const MODES = ['available', 'all'];

    protected $table = 'faq_articles';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_popular' => 'boolean',
            'tags' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FaqCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'category_id');
    }

    /**
     * @return HasMany<FaqTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(FaqTranslation::class, 'article_id');
    }

    /**
     * @return BelongsToMany<FaqArticle, $this>
     */
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'faq_article_related', 'article_id', 'related_id')->withPivot('position')->orderByPivot('position');
    }

    /**
     * Langues dont la traduction est prête.
     *
     * @return list<string>
     */
    public function readyLocales(): array
    {
        return $this->translations->where('is_ready', true)->pluck('locale')->values()->all();
    }
}
