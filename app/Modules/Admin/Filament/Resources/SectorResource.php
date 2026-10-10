<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\SectorResource\Pages\ListSectors;
use App\Modules\Admin\Filament\Support\HiddenFromSupportAgents;
use App\Modules\Admin\Models\PlatformSector;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\SectorManager;
use App\Modules\Tenancy\Models\Sector;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Secteurs d'activité (Super Admin) : le nom se saisit au clavier, sans liste préétablie. Création, modification, activation,
 * désactivation et suppression, chacune tracée dans le journal d'audit (voir SectorManager). Désactiver et supprimer demandent
 * une confirmation qui annonce l'effet sur les établissements.
 */
class SectorResource extends Resource
{
    use HiddenFromSupportAgents;

    protected static ?string $model = PlatformSector::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('admin.sectors.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.sectors.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.sectors.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.configuration');
    }

    // Les écritures passent par les actions ci-dessous (et SectorManager), pas par les pages de création / édition de Filament.
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
     * Champs du formulaire d'ajout et de modification : le nom est un champ texte libre, vérifié avant l'enregistrement.
     *
     * @return list<Section|TextInput|Textarea|Toggle>
     */
    public static function formSchema(?PlatformSector $sector = null): array
    {
        return [
            TextInput::make('name')
                ->label(__('admin.sectors.name'))
                ->placeholder(__('admin.sectors.form.name_placeholder'))
                ->helperText($sector === null ? __('admin.sectors.form.name_help') : __('admin.sectors.form.name_help').' '.__('admin.sectors.form.code', ['code' => $sector->code]))
                ->required()
                ->minLength(SectorManager::MIN_NAME)
                ->maxLength(SectorManager::MAX_NAME)
                ->autofocus()
                ->autocomplete(false)
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($sector): void {
                        if (app(SectorManager::class)->nameTaken((string) $value, $sector?->id)) {
                            $fail(__('admin.sectors.errors.name_taken'));
                        }
                    },
                ]),
            Toggle::make('is_active')
                ->label(__('admin.sectors.form.active_label'))
                ->helperText(__('admin.sectors.form.active_help'))
                ->default(true)
                ->disabled($sector?->is_default === true),
            Section::make(__('admin.sectors.form.translations'))
                ->description(__('admin.sectors.form.translations_help'))
                ->collapsed()
                ->columns(3)
                ->schema([
                    TextInput::make('name_fr')->label(__('admin.sectors.form.name_fr'))->maxLength(SectorManager::MAX_NAME),
                    TextInput::make('name_nl')->label(__('admin.sectors.form.name_nl'))->maxLength(SectorManager::MAX_NAME),
                    TextInput::make('name_en')->label(__('admin.sectors.form.name_en'))->maxLength(SectorManager::MAX_NAME),
                ]),
            Textarea::make('notes')
                ->label(__('admin.sectors.form.notes'))
                ->helperText(__('admin.sectors.form.notes_help'))
                ->rows(3)
                ->maxLength(1000),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->paginated(false)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select('sectors.*')->selectSub(
                DB::connection(PlatformAudit::CONNECTION)->table('organizations')->whereColumn('organizations.sector', 'sectors.code')->selectRaw('count(*)'),
                'merchants_count',
            ))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.sectors.name'))
                    ->description(fn (PlatformSector $record): string => $record->code.($record->is_default ? ' · '.__('admin.sectors.default') : ''))
                    ->searchable(['name', 'name_fr', 'name_nl', 'name_en', 'code'])
                    ->sortable(),
                TextColumn::make('is_active')
                    ->label(__('admin.sectors.status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => __($state ? 'admin.sectors.active' : 'admin.sectors.inactive'))
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('merchants_count')->label(__('admin.sectors.merchants'))->numeric()->alignEnd()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.sectors.status'))
                    ->placeholder(__('admin.sectors.all'))
                    ->trueLabel(__('admin.sectors.active'))
                    ->falseLabel(__('admin.sectors.inactive')),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('admin.sectors.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->modalHeading(fn (PlatformSector $record): string => __('admin.sectors.edit_title'))
                    ->fillForm(fn (PlatformSector $record): array => $record->only(['name', 'is_active', 'name_fr', 'name_nl', 'name_en', 'notes']))
                    ->schema(fn (PlatformSector $record): array => self::formSchema($record))
                    ->action(function (array $data, PlatformSector $record): void {
                        app(SectorManager::class)->update($record, $data, self::adminId());

                        Notification::make()->success()->title(__('admin.sectors.saved.updated'))->send();
                    }),
                ActionGroup::make([
                    Action::make('activate')
                        ->label(__('admin.sectors.activate'))
                        ->icon('heroicon-o-play-circle')
                        ->visible(fn (PlatformSector $record): bool => ! $record->is_active)
                        ->action(function (PlatformSector $record): void {
                            app(SectorManager::class)->setActive($record, true, self::adminId());

                            Notification::make()->success()->title(__('admin.sectors.saved.activated'))->send();
                        }),
                    Action::make('deactivate')
                        ->label(__('admin.sectors.deactivate'))
                        ->icon('heroicon-o-pause-circle')
                        ->visible(fn (PlatformSector $record): bool => $record->is_active && ! $record->is_default)
                        ->requiresConfirmation()
                        ->modalHeading(fn (PlatformSector $record): string => __('admin.sectors.confirm.deactivate_title', ['name' => $record->name]))
                        ->modalDescription(fn (PlatformSector $record): string => self::impact($record, 'deactivate'))
                        ->modalSubmitActionLabel(__('admin.sectors.confirm.deactivate_button'))
                        ->action(function (PlatformSector $record): void {
                            app(SectorManager::class)->setActive($record, false, self::adminId());

                            Notification::make()->success()->title(__('admin.sectors.saved.deactivated'))->send();
                        }),
                    Action::make('delete')
                        ->label(__('admin.sectors.delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (PlatformSector $record): bool => ! $record->is_default)
                        ->requiresConfirmation()
                        ->modalHeading(fn (PlatformSector $record): string => __('admin.sectors.confirm.delete_title', ['name' => $record->name]))
                        ->modalDescription(fn (PlatformSector $record): string => self::impact($record, 'delete'))
                        ->modalSubmitActionLabel(fn (PlatformSector $record): string => __(self::used($record) > 0 ? 'admin.sectors.confirm.delete_anyway' : 'admin.sectors.confirm.delete_button'))
                        ->action(function (PlatformSector $record): void {
                            app(SectorManager::class)->delete($record, self::adminId());

                            Notification::make()->success()->title(__('admin.sectors.saved.deleted'))->send();
                        }),
                ]),
            ]);
    }

    /** Effet d'une désactivation ou d'une suppression sur les établissements, dit en clair dans la fenêtre de confirmation. */
    public static function impact(PlatformSector $sector, string $action): string
    {
        $count = self::used($sector);
        $key = $count > 0 ? "admin.sectors.confirm.{$action}_used" : "admin.sectors.confirm.{$action}_unused";
        $default = Sector::query()->where('is_default', true)->first()?->label(app()->getLocale()) ?? 'Other';

        return trans_choice($key, $count, ['count' => $count, 'default' => $default]);
    }

    private static function used(PlatformSector $sector): int
    {
        return $sector->merchants_count ?? app(SectorManager::class)->merchants($sector);
    }

    private static function adminId(): string
    {
        return (string) (Filament::auth()->user() ?? abort(403))->getAuthIdentifier();
    }

    public static function getPages(): array
    {
        return ['index' => ListSectors::route('/')];
    }
}
