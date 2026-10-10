<?php

declare(strict_types=1);

namespace App\Modules\Admin\Models;

use App\Modules\Legal\Models\LegalDocumentVersion;

/**
 * Version d'un document juridique vue et écrite par le Super Admin (rôle `nova_platform`). Une version publiée est figée par la base.
 */
class PlatformLegalVersion extends LegalDocumentVersion
{
    protected $connection = 'pgsql_platform';

    protected $table = 'legal_document_versions';
}
