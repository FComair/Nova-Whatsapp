<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\LegalVersionResource\Pages\ListLegalVersions;
use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Admin\Models\PlatformLegalVersion;
use App\Modules\Admin\Services\LegalVersionManager;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Legal\Models\LegalDocumentVersion;
use App\Modules\Legal\Support\LegalDocument;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Documents juridiques (Super Admin, docs/28) : conditions d'utilisation, politique de confidentialité, DPA. Chaque texte est une VERSION : on
 * prépare un brouillon, on le publie avec une date d'effet, et il ne change plus jamais (la base l'interdit). Seul le Super Admin écrit ici ;
 * les clients ne font que lire et accepter.
 */
class LegalVersionResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = PlatformLegalVersion::class;

    /** Permission nécessaire pour voir cet écran (docs/38). */
    protected static ?string $viewPermission = 'legal.view';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string
    {
        return __('admin.legal.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.legal.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.legal.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.configuration');
    }

    // Le menu pointe vers « Documents juridiques » (dépôt de PDF, docs/35) ; la liste des versions texte reste accessible depuis cette page.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // Les écritures passent par les actions (et LegalVersionManager), pas par les pages de création / édition de Filament.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return list<Section|Select|TextInput|DatePicker|Toggle|Textarea>
     */
    public static function formSchema(?PlatformLegalVersion $record = null): array
    {
        $languages = ['fr' => 'Français', 'nl' => 'Nederlands', 'en' => 'English'];
        $sections = [];

        foreach ($languages as $code => $name) {
            $sections[] = Section::make($name)->collapsed($code !== 'fr')->schema([
                TextInput::make("title_{$code}")->label(__('admin.legal.form.title'))->maxLength(150),
                Textarea::make("body_{$code}")->label(__('admin.legal.form.body'))->helperText(__('admin.legal.form.body_help'))->rows(14),
                Textarea::make("changes_{$code}")->label(__('admin.legal.form.changes'))->helperText(__('admin.legal.form.changes_help'))->rows(3)->maxLength(1000),
            ]);
        }

        return [
            Select::make('document')
                ->label(__('admin.legal.document'))
                ->options(collect(LegalDocument::codes())->mapWithKeys(fn (string $code): array => [$code => __("admin.legal.documents.{$code}")])->all())
                ->required()
                ->disabled($record !== null),
            TextInput::make('version')
                ->label(__('admin.legal.version'))
                ->helperText(__('admin.legal.form.version_help'))
                ->required()
                ->maxLength(30)
                ->regex('/^[0-9A-Za-z._-]{1,30}$/')
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record, $get): void {
                        $taken = PlatformLegalVersion::query()->where('version', (string) $value)->where('document', (string) ($get('document') ?? $record?->document))
                            ->when($record !== null, fn ($query) => $query->whereKeyNot($record->id))->exists();

                        if ($taken) {
                            $fail(__('admin.legal.errors.version_taken'));
                        }
                    },
                ]),
            DatePicker::make('effective_on')->label(__('admin.legal.effective_on'))->helperText(__('admin.legal.form.effective_help'))->required()->native(false),
            Toggle::make('requires_acceptance')->label(__('admin.legal.requires_acceptance'))->helperText(__('admin.legal.form.requires_help')),
            ...$sections,
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('effective_on', 'desc')
            ->columns([
                TextColumn::make('document')->label(__('admin.legal.document'))->formatStateUsing(fn (string $state): string => __("admin.legal.documents.{$state}"))->sortable()->searchable(),
                TextColumn::make('version')->label(__('admin.legal.version'))->sortable()->searchable(),
                TextColumn::make('effective_on')->label(__('admin.legal.effective_on'))->date('d/m/Y')->sortable(),
                TextColumn::make('status')->label(__('admin.legal.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.legal.statuses.{$state}"))
                    ->color(fn (string $state): string => $state === LegalDocumentVersion::PUBLISHED ? 'success' : 'gray'),
                IconColumn::make('requires_acceptance')->label(__('admin.legal.requires_acceptance'))->boolean(),
                TextColumn::make('published_at')->label(__('admin.legal.published_at'))->dateTime('d/m/Y H:i')->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('document')->label(__('admin.legal.document'))
                    ->options(collect(LegalDocument::codes())->mapWithKeys(fn (string $code): array => [$code => __("admin.legal.documents.{$code}")])->all()),
                SelectFilter::make('status')->label(__('admin.legal.status'))
                    ->options([LegalDocumentVersion::DRAFT => __('admin.legal.statuses.draft'), LegalDocumentVersion::PUBLISHED => __('admin.legal.statuses.published')]),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('admin.legal.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (PlatformLegalVersion $record): bool => ! $record->isPublished() && AdminAccess::can('legal.upload'))
                    ->fillForm(fn (PlatformLegalVersion $record): array => self::formData($record))
                    ->schema(fn (PlatformLegalVersion $record): array => self::formSchema($record))
                    ->action(function (array $data, PlatformLegalVersion $record): void {
                        self::attempt(fn () => app(LegalVersionManager::class)->update($record, $data + ['document' => $record->document], self::adminId()), 'admin.legal.saved.updated');
                    }),
                Action::make('publish')
                    ->label(__('admin.legal.publish'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (PlatformLegalVersion $record): bool => ! $record->isPublished() && AdminAccess::can('legal.upload'))
                    ->requiresConfirmation()
                    ->modalHeading(fn (PlatformLegalVersion $record): string => __('admin.legal.confirm.publish_title', ['version' => $record->version]))
                    ->modalDescription(fn (PlatformLegalVersion $record): string => __($record->requires_acceptance ? 'admin.legal.confirm.publish_required' : 'admin.legal.confirm.publish_info'))
                    ->modalSubmitActionLabel(__('admin.legal.publish'))
                    ->action(fn (PlatformLegalVersion $record) => self::attempt(fn () => app(LegalVersionManager::class)->publish($record, self::adminId()), 'admin.legal.saved.published')),
                Action::make('delete')
                    ->label(__('admin.legal.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (PlatformLegalVersion $record): bool => ! $record->isPublished() && AdminAccess::can('legal.upload'))
                    ->requiresConfirmation()
                    ->action(fn (PlatformLegalVersion $record) => self::attempt(fn () => app(LegalVersionManager::class)->delete($record, self::adminId()), 'admin.legal.saved.deleted')),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function formData(PlatformLegalVersion $record): array
    {
        $data = ['document' => $record->document, 'version' => $record->version, 'effective_on' => $record->effective_on->toDateString(), 'requires_acceptance' => $record->requires_acceptance];

        foreach (LegalVersionManager::LOCALES as $locale) {
            $data["title_{$locale}"] = $record->title[$locale] ?? '';
            $data["body_{$locale}"] = $record->body[$locale] ?? '';
            $data["changes_{$locale}"] = $record->changes[$locale] ?? '';
        }

        return $data;
    }

    /** @param  callable(): mixed  $action */
    public static function attempt(callable $action, string $success): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title(__("admin.legal.errors.{$e->getMessage()}"))->send();

            return;
        }

        Notification::make()->success()->title(__($success))->send();
    }

    public static function adminId(): string
    {
        return (string) (Filament::auth()->user() ?? abort(403))->getAuthIdentifier();
    }

    public static function getPages(): array
    {
        return ['index' => ListLegalVersions::route('/')];
    }
}
