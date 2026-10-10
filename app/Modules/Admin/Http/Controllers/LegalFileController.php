<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Services\LegalDocumentFiles;
use App\Modules\Admin\Support\SupportAccess;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Téléchargement d'un PDF juridique par le Super Admin (docs/35) : version en vigueur ou ARCHIVÉE. Les archives ne sont jamais publiques ;
 * seul un Super Admin (ni agent de support ni éditeur de contenu) les télécharge, par cette adresse contrôlée, et chaque téléchargement est tracé.
 */
final class LegalFileController
{
    public function __invoke(string $document, string $version, LegalDocumentFiles $files): StreamedResponse
    {
        $admin = SupportAccess::admin() ?? abort(403);
        abort_if(SupportAccess::isRestricted($admin), 403);

        return $files->download($admin->id, $document, $version);
    }
}
