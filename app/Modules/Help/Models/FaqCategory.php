<?php

declare(strict_types=1);

namespace App\Modules\Help\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Catégorie du centre d'aide (docs/30). Noms et descriptions par langue ; le rôle client ne voit que les catégories actives.
 *
 * @property string $id
 * @property string $slug
 * @property string $icon
 * @property int $position
 * @property bool $is_active
 * @property array<string, string> $names
 * @property array<string, string> $descriptions
 * @property Carbon $updated_at
 */
class FaqCategory extends Model
{
    use HasUuids;

    /** Icônes que le centre d'aide sait dessiner (composant HelpIcon). */
    public const ICONS = ['rocket', 'calendar', 'chat', 'users', 'clock', 'card', 'shield', 'wrench'];

    public const LOCALES = ['fr', 'nl', 'en'];

    protected $table = 'faq_categories';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'position' => 'integer', 'names' => 'array', 'descriptions' => 'array'];
    }

    /**
     * @return HasMany<FaqArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(FaqArticle::class, 'category_id');
    }

    /** Nom dans la langue demandée, sinon français puis anglais, sinon le code. */
    public function name(string $locale): string
    {
        return $this->pick($this->names, $locale) ?? $this->slug;
    }

    public function description(string $locale): string
    {
        return $this->pick($this->descriptions, $locale) ?? '';
    }

    /**
     * @param  array<string, string>  $texts
     */
    private function pick(array $texts, string $locale): ?string
    {
        foreach ([$locale, 'fr', 'en', 'nl'] as $candidate) {
            if (filled($texts[$candidate] ?? null)) {
                return $texts[$candidate];
            }
        }

        return null;
    }
}
