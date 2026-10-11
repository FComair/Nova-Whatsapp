<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\FaqCategoryResource\Pages\ManageFaqCategories;
use App\Modules\Admin\Filament\Support\FaqActions;
use App\Modules\Admin\Models\PlatformFaqCategory;
use App\Modules\Admin\Services\FaqCategoryManager;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Help\Models\FaqCategory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Catégories du centre d'aide (docs/30 §5) : nom et description par langue, icône, ordre, activation, nombre d'articles. Une catégorie
 * qui contient encore des articles ne se supprime pas sans réaffectation explicite. Réservé au Super Admin et aux éditeurs de contenu.
 */
class FaqCategoryResource extends Resource
{
    protected static ?string $model = PlatformFaqCategory::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'faq-categories';

    public static function getModelLabel(): string
    {
        return __('faq.categories.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('faq.categories.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('faq.navigation.categories');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('faq.navigation.group');
    }

    public static function canAccess(): bool
    {
        return SupportAccess::canManageFaq() && AdminAccess::can('faq.view') && parent::canAccess();
    }

    public static function canCreate(): bool
    {
        return AdminAccess::can('faq.create');
    }

    public static function canEdit(Model $record): bool
    {
        return AdminAccess::can('faq.edit');
    }

    public static function canDelete(Model $record): bool
    {
        return AdminAccess::can('faq.delete');
    }

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select('faq_categories.*')
            ->addSelect(DB::raw('(select count(*) from faq_articles a where a.category_id = faq_categories.id) as articles_count'))
            ->addSelect(DB::raw("(select count(*) from faq_articles a where a.category_id = faq_categories.id and a.status = 'published') as published_count"));
    }

    /**
     * @return array<Component>
     */
    public static function formSchema(bool $creating): array
    {
        return [
            Section::make(__('faq.categories.general'))->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('slug')->label(__('faq.categories.slug'))->required($creating)->visible($creating)->maxLength(40)->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')->helperText(__('faq.categories.slug_help')),
                Select::make('icon')->label(__('faq.categories.icon'))->native(false)->default('rocket')->options(fn (): array => collect(FaqCategory::ICONS)->mapWithKeys(fn (string $icon): array => [$icon => __("faq.icons.{$icon}")])->all()),
                TextInput::make('position')->label(__('faq.columns.position'))->numeric()->integer()->default(100)->minValue(0)->maxValue(10000)->helperText(__('faq.categories.position_help')),
                Toggle::make('is_active')->label(__('faq.categories.active'))->default(true)->helperText(__('faq.categories.active_help')),
            ]),
            Section::make(__('faq.categories.texts'))->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema(array_merge(
                array_map(fn (string $locale): TextInput => TextInput::make("names.{$locale}")->label(__('faq.categories.name_in', ['language' => __("faq.locales.{$locale}")]))->maxLength(200)->required($locale === 'fr'), FaqCategory::LOCALES),
                array_map(fn (string $locale): Textarea => Textarea::make("descriptions.{$locale}")->label(__('faq.categories.description_in', ['language' => __("faq.locales.{$locale}")]))->rows(3)->maxLength(200), FaqCategory::LOCALES),
            )),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->paginated(false)
            ->striped()
            ->columns([
                TextColumn::make('position')->label(__('faq.columns.position'))->numeric()->sortable(),
                TextColumn::make('names')->label(__('faq.categories.name'))->weight('semibold')->wrap()
                    ->state(fn (Model $record): string => $record instanceof FaqCategory ? $record->name('fr') : '')
                    ->description(fn (Model $record): string => $record instanceof FaqCategory ? '/'.$record->slug.' · '.implode(' · ', array_map(fn (string $locale): string => strtoupper($locale).' '.(filled($record->names[$locale] ?? null) ? '✓' : '–'), FaqCategory::LOCALES)) : ''),
                TextColumn::make('icon')->label(__('faq.categories.icon'))->formatStateUsing(fn (string $state): string => __("faq.icons.{$state}"))->toggleable(),
                TextColumn::make('articles_count')->label(__('faq.categories.articles'))->numeric()
                    ->description(fn (Model $record): string => __('faq.categories.published_count', ['count' => (int) $record->getAttribute('published_count')])),
                TextColumn::make('is_active')->label(__('faq.categories.state'))->badge()
                    ->state(fn (Model $record): string => $record->getAttribute('is_active') ? 'active' : 'inactive')
                    ->formatStateUsing(fn (string $state): string => __("faq.categories.states.{$state}"))
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray')
                    ->icon(fn (string $state): string => $state === 'active' ? 'heroicon-m-check-circle' : 'heroicon-m-minus-circle'),
            ])
            ->recordActions([
                Action::make('edit')->label(__('faq.actions.edit'))->icon('heroicon-m-pencil-square')->modalHeading(__('faq.categories.edit'))
                    ->fillForm(fn (Model $record): array => ['icon' => $record->getAttribute('icon'), 'position' => $record->getAttribute('position'), 'is_active' => $record->getAttribute('is_active'), 'names' => (array) $record->getAttribute('names'), 'descriptions' => (array) $record->getAttribute('descriptions')])
                    ->schema(self::formSchema(false))
                    ->action(fn (array $data, Model $record) => FaqActions::perform(fn ($admin) => app(FaqCategoryManager::class)->update($admin, self::category($record), $data), __('faq.saved'))),
                Action::make('toggle')->label(fn (Model $record): string => $record->getAttribute('is_active') ? __('faq.categories.deactivate') : __('faq.categories.activate'))
                    ->icon(fn (Model $record): string => $record->getAttribute('is_active') ? 'heroicon-m-eye-slash' : 'heroicon-m-eye')->color('gray')
                    ->requiresConfirmation(fn (Model $record): bool => (bool) $record->getAttribute('is_active') && (int) $record->getAttribute('published_count') > 0)
                    ->modalHeading(__('faq.categories.deactivate_heading'))->modalDescription(fn (Model $record): string => __('faq.categories.deactivate_description', ['count' => (int) $record->getAttribute('published_count')]))
                    ->action(fn (Model $record) => FaqActions::perform(fn ($admin) => app(FaqCategoryManager::class)->update($admin, self::category($record), ['is_active' => ! $record->getAttribute('is_active')]), __('faq.saved'))),
                Action::make('remove')->label(__('faq.actions.delete'))->icon('heroicon-m-trash')->color('danger')
                    ->modalHeading(__('faq.categories.delete_heading'))
                    ->modalDescription(fn (Model $record): string => (int) $record->getAttribute('articles_count') > 0 ? __('faq.categories.delete_move', ['count' => (int) $record->getAttribute('articles_count')]) : __('faq.categories.delete_empty'))
                    ->schema(fn (Model $record): array => (int) $record->getAttribute('articles_count') > 0 ? [
                        Select::make('move_to')->label(__('faq.categories.move_to'))->required()->native(false)
                            ->options(fn (): array => PlatformFaqCategory::query()->where('id', '!=', $record->getKey())->orderBy('position')->get()->mapWithKeys(fn (FaqCategory $category): array => [$category->id => $category->name('fr')])->all()),
                    ] : [])
                    ->action(function (array $data, Model $record): void {
                        FaqActions::perform(function ($admin) use ($data, $record): void {
                            $manager = app(FaqCategoryManager::class);

                            isset($data['move_to'])
                                ? $manager->reassignAndDelete($admin, self::category($record), PlatformFaqCategory::query()->findOrFail((string) $data['move_to']))
                                : $manager->delete($admin, self::category($record));
                        }, __('faq.categories.deleted'));
                    }),
            ]);
    }

    public static function category(Model $record): PlatformFaqCategory
    {
        return PlatformFaqCategory::query()->findOrFail($record->getKey());
    }

    public static function getPages(): array
    {
        return ['index' => ManageFaqCategories::route('/')];
    }
}
