<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages\Legal;

use App\Modules\Admin\Filament\Pages\AdminDashboard;
use App\Modules\Admin\Filament\Resources\LegalVersionResource;
use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Admin\Services\LegalDocumentFiles;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Documents juridiques, page principale (Super Admin, docs/35) : pour chacun des trois documents, la version en vigueur (nom du fichier, version,
 * taille, dernière mise à jour, déposé par, lien pour l'ouvrir ou la télécharger) et les boutons « Déposer / Remplacer » et « Historique ».
 * Réservée au Super Admin : ni les agents de support ni les éditeurs de contenu ne la voient.
 */
class LegalDocuments extends Page
{
    use SuperAdminOnly;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'legal-documents';

    protected string $view = 'admin.legal.overview';

    public static function getNavigationLabel(): string
    {
        return __('admin.legal_files.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.configuration');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.legal_files.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('admin.legal_files.subtitle');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function documents(): array
    {
        return app(LegalDocumentFiles::class)->overview();
    }

    /** @return array{back: string, texts: string} */
    public function links(): array
    {
        return ['back' => AdminDashboard::getUrl(), 'texts' => LegalVersionResource::getUrl()];
    }
}
