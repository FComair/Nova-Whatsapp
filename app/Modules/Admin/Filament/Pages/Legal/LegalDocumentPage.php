<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages\Legal;

use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Legal\Support\LegalDocument;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Base des écrans d'UN document juridique (fiche, dépôt, historique) : le document se désigne par `?document=terms|privacy|dpa`, la page
 * refuse tout autre code. Aucun de ces écrans n'apparaît dans le menu : on y arrive depuis « Documents juridiques » et on en revient par « Retour ».
 */
abstract class LegalDocumentPage extends Page
{
    use SuperAdminOnly;

    /** Permission nécessaire pour voir ces écrans (docs/38). */
    protected static ?string $viewPermission = 'legal.view';

    #[Url(as: 'document')]
    public string $document = '';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        abort_unless(in_array($this->document, LegalDocument::codes(), true), 404);
    }

    /** Nom du document dans la langue de l'interface : « Conditions d'utilisation », « Politique de confidentialité », « DPA ». */
    public function documentName(): string
    {
        return (string) __("ui.legal.documents.{$this->document}");
    }

    public function getTitle(): string|Htmlable
    {
        return $this->documentName();
    }

    /**
     * @return array<string, string>
     */
    public static function params(string $document): array
    {
        return ['document' => $document];
    }
}
