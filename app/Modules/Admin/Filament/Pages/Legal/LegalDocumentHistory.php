<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages\Legal;

use App\Modules\Admin\Services\LegalDocumentFiles;

/**
 * Historique des versions d'un document juridique (Super Admin, docs/35) : version en vigueur, planifiées et archivées, avec date, fichier, taille,
 * auteur du dépôt et lien de téléchargement (les archives ne se téléchargent qu'ici, jamais publiquement). Retour : « Retour à [document] ».
 */
class LegalDocumentHistory extends LegalDocumentPage
{
    protected static ?string $slug = 'legal-document-history';

    protected string $view = 'admin.legal.history';

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        return app(LegalDocumentFiles::class)->history($this->document);
    }

    /** @return array{back: string, upload: string} */
    public function links(): array
    {
        return ['back' => LegalDocumentDetail::getUrl(self::params($this->document)), 'upload' => LegalDocumentUpload::getUrl(self::params($this->document))];
    }
}
