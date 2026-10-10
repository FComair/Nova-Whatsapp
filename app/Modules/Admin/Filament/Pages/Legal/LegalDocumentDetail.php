<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages\Legal;

use App\Modules\Admin\Services\LegalDocumentFiles;

/**
 * Fiche d'un document juridique (Super Admin, docs/35) : la version en vigueur (fichier, version, taille, dernière mise à jour, déposé par, lien),
 * les versions récentes, et l'accès au dépôt d'un nouveau PDF et à l'historique complet. « Retour aux documents juridiques » ramène à la liste.
 */
class LegalDocumentDetail extends LegalDocumentPage
{
    protected static ?string $slug = 'legal-document';

    protected string $view = 'admin.legal.detail';

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(): array
    {
        return array_slice(app(LegalDocumentFiles::class)->history($this->document), 0, 5);
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        foreach (app(LegalDocumentFiles::class)->history($this->document) as $row) {
            if ($row['state'] === 'current') {
                return $row;
            }
        }

        return null;
    }

    public function total(): int
    {
        return count(app(LegalDocumentFiles::class)->history($this->document));
    }

    /** @return array{back: string, upload: string, history: string} */
    public function links(): array
    {
        return [
            'back' => LegalDocuments::getUrl(),
            'upload' => LegalDocumentUpload::getUrl(self::params($this->document)),
            'history' => LegalDocumentHistory::getUrl(self::params($this->document)),
        ];
    }
}
