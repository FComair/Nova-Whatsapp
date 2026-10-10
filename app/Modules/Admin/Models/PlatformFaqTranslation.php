<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Help\Models\FaqTranslation;

/**
 * Vue Super Admin d'une traduction d'article (docs/30), brouillons compris.
 */
class PlatformFaqTranslation extends FaqTranslation
{
    protected $connection = 'pgsql_platform';
}
