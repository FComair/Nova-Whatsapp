<?php

declare(strict_types=1);

namespace App\Modules\Legal\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Une version d'un document juridique (docs/28). Publiée, elle ne change plus jamais ; un nouveau texte est une nouvelle version.
 *
 * @property string $id
 * @property string $document terms, privacy ou dpa
 * @property string $version
 * @property Carbon $effective_on
 * @property string $status draft ou published
 * @property bool $requires_acceptance version juridiquement significative : une nouvelle acceptation est exigée
 * @property array<string, string> $title
 * @property array<string, string> $body Markdown par langue
 * @property array<string, string> $changes résumé des changements par langue
 * @property Carbon|null $published_at
 * @property string|null $file_path chemin aléatoire du PDF déposé (disque privé) ; null = version saisie en texte
 * @property string|null $file_name nom d'origine du PDF
 * @property int|null $file_size taille en octets
 * @property string|null $file_sha256 empreinte du PDF
 * @property string|null $uploaded_by Super Admin qui l'a déposé
 * @property Carbon|null $uploaded_at
 */
class LegalDocumentVersion extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'requires_acceptance' => 'boolean',
            'title' => 'array',
            'body' => 'array',
            'changes' => 'array',
            'published_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /** Version déposée en PDF (et non saisie en texte). */
    public function hasFile(): bool
    {
        return $this->file_path !== null;
    }
}
