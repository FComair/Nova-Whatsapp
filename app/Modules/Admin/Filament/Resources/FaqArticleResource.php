<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages\CreateFaqArticle;
use App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages\EditFaqArticle;
use App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages\ListFaqArticles;
use App\Modules\Admin\Filament\Resources\FaqArticleResource\Pages\PreviewFaqArticle;
use App\Modules\Admin\Filament\Support\FaqActions;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformFaqArticle;
use App\Modules\Admin\Models\PlatformFaqCategory;
use App\Modules\Admin\Services\FaqManager;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Help\Models\FaqArticle;
use App\Modules\Help\Models\FaqCategory;
use App\Modules\Help\Support\FaqPublishing;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * FAQ / articles d'aide (docs/30) : liste, éditeur à onglets par langue, aperçu client, publication. Lit et écrit avec le rôle
 * `nova_platform` (brouillons et archives compris) ; réservé au Super Admin et aux éditeurs de contenu.
 */
class FaqArticleResource extends Resource
{
    protected static ?string $model = PlatformFaqArticle::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'faq';

    /** Couleur et pictogramme de chaque état et de chaque état de traduction : jamais la couleur seule, le libellé est toujours affiché. */
    public const STATUS_STYLE = [
        FaqArticle::DRAFT => ['gray', 'heroicon-m-pencil-square'],
        FaqArticle::PUBLISHED => ['success', 'heroicon-m-globe-alt'],
        FaqArticle::ARCHIVED => ['warning', 'heroicon-m-archive-box'],
    ];

    public const LANGUAGE_STYLE = [
        'ready' => ['success', 'heroicon-m-check-circle'],
        'draft' => ['warning', 'heroicon-m-pencil'],
        'missing' => ['gray', 'heroicon-m-minus-circle'],
    ];

    public static function getModelLabel(): string
    {
        return __('faq.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('faq.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('faq.navigation.articles');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('faq.navigation.group');
    }

    /** Pastille du menu : brouillons à terminer ou à publier. */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = PlatformFaqArticle::query()->where('status', FaqArticle::DRAFT)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'gray';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('faq.navigation.badge');
    }

    /** Réservé au Super Admin et aux éditeurs de contenu (pas aux agents de support). */
    public static function canAccess(): bool
    {
        return SupportAccess::canManageFaq() && parent::canAccess();
    }

    public static function canDelete(Model $record): bool
    {
        return $record instanceof FaqArticle && $record->status !== FaqArticle::PUBLISHED;
    }

    /** La requête joint d'autres tables (qui ont aussi une colonne « id ») : la clé de l'article est qualifiée. */
    public static function getRecordRouteKeyName(): ?string
    {
        return 'faq_articles.id';
    }

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        $translation = fn (string $locale, string $column): string => "(select t.{$column} from faq_translations t where t.article_id = faq_articles.id and t.locale = '{$locale}')";
        $state = fn (string $locale): string => "coalesce((select case when t.is_ready then 'ready' else 'draft' end from faq_translations t where t.article_id = faq_articles.id and t.locale = '{$locale}'), 'missing')";
        $feedback = fn (string $filter): string => "(select count(*) {$filter} from help_article_feedback f where f.category = cat.slug and f.slug = faq_articles.slug)";

        return parent::getEloquentQuery()
            ->join('faq_categories as cat', 'cat.id', '=', 'faq_articles.category_id')
            ->leftJoin('platform_admins as editor', 'editor.id', '=', 'faq_articles.last_editor_id')
            ->select('faq_articles.*', 'cat.slug as category_slug', 'editor.email as editor_email')
            ->addSelect(DB::raw("coalesce(cat.names->>'fr', cat.names->>'en', cat.names->>'nl', cat.slug) as category_name"))
            ->addSelect(DB::raw('coalesce('.$translation('fr', 'question').', '.$translation('en', 'question').', '.$translation('nl', 'question').', faq_articles.slug) as title'))
            ->addSelect(DB::raw($state('fr').' as lang_fr'), DB::raw($state('nl').' as lang_nl'), DB::raw($state('en').' as lang_en'))
            ->addSelect(DB::raw($feedback('filter (where f.helpful)').' as helpful_yes'), DB::raw($feedback('filter (where not f.helpful)').' as helpful_no'), DB::raw($feedback('').' as feedback_total'));
    }

    /**
     * Fiche de l'article : général, puis un onglet par langue (jamais de langues mélangées dans un même texte).
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('faq.form.general'))->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                Select::make('category_id')->label(__('faq.form.category'))->required()->native(false)->searchable()
                    ->options(fn (): array => PlatformFaqCategory::query()->orderBy('position')->get()->mapWithKeys(fn (FaqCategory $category): array => [$category->id => $category->name('fr')])->all()),
                TextInput::make('slug')->label(__('faq.form.slug'))->maxLength(80)->regex('/^[a-z0-9]+(-[a-z0-9]+)*$/')->helperText(__('faq.form.slug_help')),
                TextInput::make('position')->label(__('faq.form.position'))->numeric()->integer()->default(100)->minValue(0)->maxValue(10000)->helperText(__('faq.form.position_help')),
                Select::make('publication')->label(__('faq.form.publication'))->required()->native(false)->default('available')
                    ->options(['available' => __('faq.publication.available'), 'all' => __('faq.publication.all')])->helperText(__('faq.form.publication_help')),
                TagsInput::make('tags')->label(__('faq.form.tags'))->helperText(__('faq.form.tags_help'))->columnSpanFull(),
                Select::make('related')->label(__('faq.form.related'))->multiple()->searchable()->native(false)->helperText(__('faq.form.related_help'))->columnSpanFull()
                    ->getSearchResultsUsing(fn (string $search, ?Model $record): array => self::relatedOptions($search, $record?->getKey()))
                    ->getOptionLabelsUsing(fn (array $values): array => self::relatedLabels($values)),
                Toggle::make('is_popular')->label(__('faq.form.popular'))->helperText(__('faq.form.popular_help')),
            ]),
            Tabs::make('languages')->columnSpanFull()->tabs(array_map(fn (string $locale): Tab => self::languageTab($locale), FaqCategory::LOCALES)),
        ]);
    }

    private static function languageTab(string $locale): Tab
    {
        $path = "translations.{$locale}";
        $ready = fn (callable $get): bool => (bool) $get("{$path}.is_ready");

        return Tab::make(strtoupper($locale))->label(__("faq.locales.{$locale}"))
            ->badge(fn (?Model $record): string => __('faq.language_state.'.self::stateOf($record, $locale)))
            ->badgeColor(fn (?Model $record): string => self::LANGUAGE_STYLE[self::stateOf($record, $locale)][0])
            ->schema([
                TextInput::make("{$path}.question")->label(__('faq.form.question'))->maxLength(160)->required($ready),
                Textarea::make("{$path}.summary")->label(__('faq.form.summary'))->rows(2)->maxLength(300)->required($ready)->helperText(__('faq.form.summary_help')),
                MarkdownEditor::make("{$path}.body")->label(__('faq.form.answer'))->minHeight('18rem')->required($ready)->helperText(__('faq.form.answer_help'))
                    ->toolbarButtons([['bold', 'italic', 'link'], ['heading'], ['bulletList', 'orderedList'], ['blockquote', 'codeBlock'], ['undo', 'redo']]),
                Toggle::make("{$path}.is_ready")->label(__('faq.form.ready'))->helperText(__('faq.form.ready_help')),
            ]);
    }

    /** État de la traduction d'un article dans une langue : ready, draft ou missing. */
    public static function stateOf(?Model $record, string $locale): string
    {
        if (! $record instanceof FaqArticle || ! $record->exists) {
            return 'missing';
        }

        return FaqPublishing::completeness($record->loadMissing('translations'))[$locale];
    }

    /**
     * @return array<string, string>
     */
    private static function relatedOptions(string $search, mixed $exceptId): array
    {
        $like = '%'.addcslashes($search, '\\%_').'%';

        return DB::connection(PlatformAudit::CONNECTION)->table('faq_articles as a')
            ->join('faq_translations as t', 't.article_id', '=', 'a.id')
            ->join('faq_categories as c', 'c.id', '=', 'a.category_id')
            ->when($exceptId, fn ($q) => $q->where('a.id', '!=', $exceptId))
            ->where(fn ($q) => $q->where('t.question', 'ilike', $like)->orWhere('a.slug', 'ilike', $like))
            ->where('a.status', '!=', FaqArticle::ARCHIVED)
            ->orderBy('t.question')->limit(30)
            ->get(['a.id', 't.question', 't.locale', 'c.slug as category'])
            ->unique('id')->mapWithKeys(fn (object $row): array => [$row->id => $row->category.' · '.$row->question])->all();
    }

    /**
     * @param  array<array-key, mixed>  $ids
     * @return array<string, string>
     */
    private static function relatedLabels(array $ids): array
    {
        return DB::connection(PlatformAudit::CONNECTION)->table('faq_articles as a')
            ->join('faq_categories as c', 'c.id', '=', 'a.category_id')
            ->whereIn('a.id', $ids)
            ->get(['a.id', 'a.slug', 'c.slug as category'])
            ->mapWithKeys(function (object $row): array {
                $question = DB::connection(PlatformAudit::CONNECTION)->table('faq_translations')->where('article_id', $row->id)->orderByRaw("case locale when 'fr' then 0 when 'en' then 1 else 2 end")->value('question');

                return [$row->id => $row->category.' · '.($question ?? $row->slug)];
            })->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (Model $record): string => static::getUrl('edit', ['record' => $record]))
            ->striped()
            ->defaultPaginationPageOption(25)
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->columns([
                TextColumn::make('title')->label(__('faq.columns.question'))->wrap()->weight('semibold')->limit(90)->extraCellAttributes(['style' => 'min-width: 16rem'])
                    ->description(fn (Model $record): string => '/'.$record->getAttribute('category_slug').'/'.$record->getAttribute('slug'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('title '.($direction === 'asc' ? 'asc' : 'desc'))),
                TextColumn::make('category_name')->label(__('faq.columns.category'))->badge()->color('gray')->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('category_name '.($direction === 'asc' ? 'asc' : 'desc'))),
                TextColumn::make('status')->label(__('faq.columns.status'))->badge()->sortable()
                    ->formatStateUsing(fn (string $state): string => __("faq.statuses.{$state}"))
                    ->color(fn (string $state): string => self::STATUS_STYLE[$state][0] ?? 'gray')
                    ->icon(fn (string $state): string => self::STATUS_STYLE[$state][1] ?? 'heroicon-m-question-mark-circle'),
                ...array_map(fn (string $locale): TextColumn => self::languageColumn($locale), ['fr', 'nl', 'en']),
                TextColumn::make('editor_email')->label(__('faq.columns.editor'))->placeholder('—')->limit(24)->toggleable(),
                TextColumn::make('created_at')->label(__('faq.columns.created'))->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label(__('faq.columns.updated'))->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('position')->label(__('faq.columns.position'))->numeric()->sortable()->toggleable(),
                TextColumn::make('feedback_total')->label(__('faq.columns.feedback'))->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('feedback_total '.($direction === 'asc' ? 'asc' : 'desc')))
                    ->state(fn (Model $record): string => (int) $record->getAttribute('feedback_total') === 0 ? '—' : __('faq.feedback_summary', ['yes' => (int) $record->getAttribute('helpful_yes'), 'no' => (int) $record->getAttribute('helpful_no')]))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('faq.columns.status'))->multiple()
                    ->options(fn (): array => collect(FaqArticle::STATUSES)->mapWithKeys(fn (string $status): array => [$status => __("faq.statuses.{$status}")])->all()),
                SelectFilter::make('category')->label(__('faq.columns.category'))
                    ->options(fn (): array => PlatformFaqCategory::query()->orderBy('position')->get()->mapWithKeys(fn (FaqCategory $category): array => [$category->id => $category->name('fr')])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $id) => $q->where('faq_articles.category_id', $id))),
                SelectFilter::make('language')->label(__('faq.filters.language'))
                    ->options(fn (): array => collect(FaqCategory::LOCALES)->mapWithKeys(fn (string $locale): array => [$locale => __('faq.filters.has_language', ['language' => __("faq.locales.{$locale}")])])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $locale) => $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('faq_translations')->whereColumn('faq_translations.article_id', 'faq_articles.id')->where('faq_translations.locale', $locale)->where('faq_translations.is_ready', true)))),
                Filter::make('incomplete')->label(__('faq.filters.incomplete'))->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereRaw('(select count(*) from faq_translations t where t.article_id = faq_articles.id and t.is_ready) < 3')),
                SelectFilter::make('author')->label(__('faq.filters.author'))
                    ->options(fn (): array => DB::connection(PlatformAudit::CONNECTION)->table('platform_admins')->orderBy('email')->pluck('email', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $id) => $q->where(fn ($inner) => $inner->where('faq_articles.author_id', $id)->orWhere('faq_articles.last_editor_id', $id)))),
                Filter::make('dates')->label(__('faq.filters.dates'))
                    ->schema([
                        DatePicker::make('from')->label(__('faq.filters.from'))->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label(__('faq.filters.until'))->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where('faq_articles.updated_at', '>=', $date.' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where('faq_articles.updated_at', '<=', $date.' 23:59:59'))),
            ])
            ->recordActions([
                Action::make('edit')->label(__('faq.actions.edit'))->icon('heroicon-m-pencil-square')->url(fn (Model $record): string => static::getUrl('edit', ['record' => $record])),
                Action::make('preview')->label(__('faq.actions.preview'))->icon('heroicon-m-eye')->color('gray')->url(fn (Model $record): string => static::getUrl('preview', ['record' => $record])),
                Action::make('publish')->label(__('faq.actions.publish'))->icon('heroicon-m-globe-alt')->color('success')
                    ->visible(fn (Model $record): bool => $record->getAttribute('status') !== FaqArticle::PUBLISHED)
                    ->requiresConfirmation()->modalHeading(__('faq.actions.publish_heading'))->modalDescription(fn (Model $record): string => self::publishNote($record))
                    ->action(fn (Model $record) => FaqActions::perform(fn ($admin) => app(FaqManager::class)->publish($admin, self::article($record)), __('faq.done.published'))),
                Action::make('unpublish')->label(__('faq.actions.unpublish'))->icon('heroicon-m-eye-slash')->color('warning')
                    ->visible(fn (Model $record): bool => $record->getAttribute('status') === FaqArticle::PUBLISHED)
                    ->requiresConfirmation()->modalHeading(__('faq.actions.unpublish_heading'))->modalDescription(__('faq.actions.unpublish_description'))
                    ->action(fn (Model $record) => FaqActions::perform(fn ($admin) => app(FaqManager::class)->unpublish($admin, self::article($record)), __('faq.done.unpublished'))),
                Action::make('duplicate')->label(__('faq.actions.duplicate'))->icon('heroicon-m-document-duplicate')->color('gray')
                    ->action(fn (Model $record) => FaqActions::perform(fn ($admin) => app(FaqManager::class)->duplicate($admin, self::article($record)), __('faq.done.duplicated'))),
                Action::make('archive')->label(__('faq.actions.archive'))->icon('heroicon-m-archive-box')->color('gray')
                    ->visible(fn (Model $record): bool => $record->getAttribute('status') !== FaqArticle::ARCHIVED)
                    ->requiresConfirmation()->modalHeading(__('faq.actions.archive_heading'))->modalDescription(__('faq.actions.archive_description'))
                    ->action(fn (Model $record) => FaqActions::perform(fn ($admin) => app(FaqManager::class)->archive($admin, self::article($record)), __('faq.done.archived'))),
                Action::make('restore')->label(__('faq.actions.restore'))->icon('heroicon-m-arrow-uturn-left')->color('gray')
                    ->visible(fn (Model $record): bool => $record->getAttribute('status') === FaqArticle::ARCHIVED)
                    ->action(fn (Model $record) => FaqActions::perform(fn ($admin) => app(FaqManager::class)->restore($admin, self::article($record)), __('faq.done.restored'))),
                Action::make('delete')->label(__('faq.actions.delete'))->icon('heroicon-m-trash')->color('danger')
                    ->visible(fn (Model $record): bool => $record->getAttribute('status') !== FaqArticle::PUBLISHED)
                    ->requiresConfirmation()->modalHeading(__('faq.actions.delete_heading'))->modalDescription(__('faq.actions.delete_description'))
                    ->action(fn (Model $record) => FaqActions::perform(fn ($admin) => app(FaqManager::class)->delete($admin, self::article($record)), __('faq.done.deleted'))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulk('publish', 'heroicon-m-globe-alt', fn (FaqManager $m, $admin, PlatformFaqArticle $a) => $m->publish($admin, $a), confirm: true),
                    self::bulk('unpublish', 'heroicon-m-eye-slash', fn (FaqManager $m, $admin, PlatformFaqArticle $a) => $m->unpublish($admin, $a), confirm: true),
                    self::bulk('archive', 'heroicon-m-archive-box', fn (FaqManager $m, $admin, PlatformFaqArticle $a) => $m->archive($admin, $a), confirm: true),
                    BulkAction::make('category')->label(__('faq.bulk.category'))->icon('heroicon-m-folder-arrow-down')->deselectRecordsAfterCompletion()
                        ->schema([Select::make('category_id')->label(__('faq.columns.category'))->required()->native(false)
                            ->options(fn (): array => PlatformFaqCategory::query()->orderBy('position')->get()->mapWithKeys(fn (FaqCategory $category): array => [$category->id => $category->name('fr')])->all())])
                        ->action(fn (Collection $records, array $data) => FaqActions::bulkResult(app(FaqManager::class)->bulk(FaqActions::admin(), $records->pluck('id')->all(), 'category', fn (PlatformFaqArticle $a) => app(FaqManager::class)->move(FaqActions::admin(), $a, (string) $data['category_id'])))),
                    self::bulk('delete', 'heroicon-m-trash', fn (FaqManager $m, $admin, PlatformFaqArticle $a) => $m->delete($admin, $a), confirm: true, danger: true),
                ])->label(__('faq.bulk.label')),
            ])
            ->emptyStateHeading(__('faq.empty.heading'))
            ->emptyStateDescription(__('faq.empty.description'))
            ->emptyStateIcon('heroicon-o-book-open');
    }

    private static function languageColumn(string $locale): TextColumn
    {
        return TextColumn::make("lang_{$locale}")->label(strtoupper($locale))->badge()
            ->formatStateUsing(fn (string $state): string => strtoupper($locale).' · '.__("faq.language_state.{$state}"))
            ->color(fn (string $state): string => self::LANGUAGE_STYLE[$state][0] ?? 'gray')
            ->icon(fn (string $state): string => self::LANGUAGE_STYLE[$state][1] ?? 'heroicon-m-minus-circle')
            ->tooltip(fn (string $state): string => __("faq.locales.{$locale}").' : '.__("faq.language_state.{$state}"));
    }

    /**
     * @param  callable(FaqManager, PlatformAdmin, PlatformFaqArticle): mixed  $operation
     */
    private static function bulk(string $name, string $icon, callable $operation, bool $confirm = false, bool $danger = false): BulkAction
    {
        $action = BulkAction::make($name)->label(__("faq.bulk.{$name}"))->icon($icon)->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => FaqActions::bulkResult(app(FaqManager::class)->bulk(FaqActions::admin(), $records->pluck('id')->all(), $name, fn (PlatformFaqArticle $article) => $operation(app(FaqManager::class), FaqActions::admin(), $article))));

        if ($confirm) {
            $action->requiresConfirmation()->modalHeading(__("faq.bulk.{$name}_heading"))->modalDescription(__("faq.bulk.{$name}_description"));
        }

        return $danger ? $action->color('danger') : $action;
    }

    /** Ce qui sera publié, dit avant de publier (langues visibles, règle choisie). */
    public static function publishNote(Model $record): string
    {
        $article = self::article($record)->load('translations');
        $missing = FaqPublishing::missing($article);

        if ($missing !== []) {
            return __('faq.actions.publish_blocked', ['locales' => implode(', ', array_map('strtoupper', $missing))]);
        }

        return __('faq.actions.publish_description', ['locales' => implode(', ', array_map('strtoupper', $article->readyLocales()))]);
    }

    public static function article(Model $record): PlatformFaqArticle
    {
        return PlatformFaqArticle::query()->findOrFail($record->getKey());
    }

    /**
     * Recherche : question, résumé et texte de toutes les langues, étiquettes, catégorie, adresse.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function search(Builder $query, string $search): Builder
    {
        $like = '%'.addcslashes(trim($search), '\\%_').'%';

        return $query->where(function (Builder $inner) use ($like): void {
            $inner->where('faq_articles.slug', 'ilike', $like)
                ->orWhereRaw('faq_articles.tags::text ilike ?', [$like])
                ->orWhereRaw('cat.names::text ilike ? or cat.slug ilike ?', [$like, $like])
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('faq_translations')->whereColumn('faq_translations.article_id', 'faq_articles.id')
                    ->where(fn ($q) => $q->where('faq_translations.question', 'ilike', $like)->orWhere('faq_translations.summary', 'ilike', $like)->orWhere('faq_translations.body', 'ilike', $like)));
        });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFaqArticles::route('/'),
            'create' => CreateFaqArticle::route('/create'),
            'edit' => EditFaqArticle::route('/{record}/edit'),
            'preview' => PreviewFaqArticle::route('/{record}/preview'),
        ];
    }
}
