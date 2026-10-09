<?php

declare(strict_types=1);

namespace App\Modules\Feedback\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Le client a ouvert le lien d'avis public (Google ou Trustpilot). Seule la plateforme est notée : ce qu'il y écrit n'est jamais connu.
 *
 * @property string $id
 * @property string $platform
 */
final class ReviewLinkClick extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];
}
