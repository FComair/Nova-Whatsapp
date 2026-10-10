<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Help\Models\FaqArticle;

/**
 * Vue Super Admin des articles d'aide (docs/30) : lue et écrite avec le rôle PostgreSQL `nova_platform`, brouillons et archives compris.
 * Traductions, catégorie et articles liés suivent la même connexion.
 */
class PlatformFaqArticle extends FaqArticle
{
    protected $connection = 'pgsql_platform';
}
