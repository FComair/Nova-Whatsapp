<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Help\Models\FaqCategory;

/**
 * Vue Super Admin des catégories d'aide (docs/30) : lue et écrite avec le rôle PostgreSQL `nova_platform`, catégories inactives comprises.
 */
class PlatformFaqCategory extends FaqCategory
{
    protected $connection = 'pgsql_platform';
}
