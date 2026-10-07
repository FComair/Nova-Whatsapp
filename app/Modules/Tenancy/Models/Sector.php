<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Secteur d'activité, vu par l'espace client (lecture seule). Géré par le Super Admin (voir PlatformSector).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $name_fr
 * @property string|null $name_nl
 * @property string|null $name_en
 * @property bool $is_active
 * @property bool $is_default
 */
class Sector extends Model
{
    use HasUuids;

    protected $table = 'sectors';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean'];
    }

    /** Nom dans la langue demandée ; à défaut, le nom principal saisi par le Super Admin. */
    public function label(string $locale): string
    {
        $translated = match ($locale) {
            'fr' => $this->name_fr,
            'nl' => $this->name_nl,
            'en' => $this->name_en,
            default => null,
        };

        return filled($translated) ? (string) $translated : $this->name;
    }
}
