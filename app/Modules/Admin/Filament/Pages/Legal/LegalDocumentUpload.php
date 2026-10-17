<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Pages\Legal;

use App\Modules\Admin\Exceptions\LegalFileRejected;
use App\Modules\Admin\Services\LegalDocumentFiles;
use App\Modules\Admin\Support\SupportAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Dépôt d'un nouveau PDF pour un document juridique (Super Admin, docs/35). Le fichier est contrôlé dans le navigateur (type, taille) puis par le
 * serveur (extension, type MIME, signature, taille, contenu actif) ; une barre de progression accompagne l'envoi. Avant de remplacer la version en
 * vigueur, une confirmation rappelle que la précédente sera archivée. Quitter la page avec un fichier choisi mais non déposé demande confirmation.
 *
 * @property-read Schema $form
 */
class LegalDocumentUpload extends LegalDocumentPage
{
    protected static ?string $slug = 'legal-document-upload';

    protected string $view = 'admin.legal.upload';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        parent::mount();
        $this->form->fill(['requires_acceptance' => false]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            FileUpload::make('file')
                ->label(__('admin.legal_files.upload.file'))
                ->helperText(__('admin.legal_files.upload.file_help', ['max' => LegalDocumentFiles::MAX_MB]))
                ->acceptedFileTypes(['application/pdf'])
                ->maxSize(LegalDocumentFiles::MAX_MB * 1024)
                ->maxFiles(1)
                ->storeFiles(false)
                ->previewable(false)
                ->required()
                ->live()
                ->validationMessages(['required' => __('admin.legal_files.errors.required')]),
            Toggle::make('requires_acceptance')->label(__('admin.legal_files.upload.requires'))->helperText(__('admin.legal_files.upload.requires_help')),
            Textarea::make('changes')->label(__('admin.legal_files.upload.changes'))->helperText(__('admin.legal_files.upload.changes_help'))->rows(3)->maxLength(1000),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('replace')
                ->label(__('admin.legal_files.actions.replace'))
                ->icon('heroicon-m-arrow-up-tray')
                ->disabled(fn (): bool => empty($this->data['file']))
                ->requiresConfirmation()
                ->modalHeading(fn (): string => __('admin.legal_files.confirm.heading', ['name' => $this->documentName()]))
                ->modalDescription(fn (): string => __('admin.legal_files.confirm.body', ['name' => $this->documentName()]))
                ->modalSubmitActionLabel(__('admin.legal_files.confirm.submit'))
                ->modalCancelActionLabel(__('admin.legal_files.confirm.back'))
                ->action(fn () => $this->submit()),
        ];
    }

    public function submit(): void
    {
        $admin = SupportAccess::admin() ?? abort(403);
        abort_if(SupportAccess::isRestricted($admin), 403);

        $state = $this->form->getState();
        $file = Arr::first(Arr::wrap($state['file'] ?? null));

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['data.file' => __('admin.legal_files.errors.required')]);
        }

        try {
            app(LegalDocumentFiles::class)->replace($admin->id, $this->document, $file, (bool) ($state['requires_acceptance'] ?? false), $state['changes'] ?? null);
        } catch (LegalFileRejected $e) {
            Notification::make()->danger()->title(__('admin.legal_files.errors.title'))->body($e->message())->send();

            throw ValidationException::withMessages(['data.file' => $e->message()]);
        }

        // La page est quittée volontairement : plus d'avertissement « fichier non déposé ».
        $this->js('window.rendivooLegalLeft = true');
        Notification::make()->success()->title(__('admin.legal_files.success', ['name' => $this->documentName()]))->send();
        $this->redirect(LegalDocumentDetail::getUrl(self::params($this->document)));
    }

    /** @return array{back: string} */
    public function links(): array
    {
        return ['back' => LegalDocumentDetail::getUrl(self::params($this->document))];
    }
}
