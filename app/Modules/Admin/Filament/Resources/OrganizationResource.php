<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Modules\Admin\Filament\Resources\OrganizationResource\RelationManagers\ActivityRelationManager;
use App\Modules\Admin\Filament\Resources\OrganizationResource\RelationManagers\InvoicesRelationManager;
use App\Modules\Admin\Filament\Resources\OrganizationResource\RelationManagers\TicketsRelationManager;
use App\Modules\Admin\Filament\Support\ClientActions;
use App\Modules\Admin\Filament\Support\SensitiveActionFields;
use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Models\PlatformSector;
use App\Modules\Admin\Services\ClientDirectory;
use App\Modules\Admin\Services\ClientManager;
use App\Modules\Admin\Services\ClientProfile;
use App\Modules\Admin\Services\OrganizationLifecycle;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Tenancy\Models\Organization;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Clients (organisations) pour le Super Admin (docs/31) : liste complète (état d'abonnement, formule, échéance, MRR, contact, membres,
 * dernière activité, étiquettes), recherche, filtres, vues enregistrées, actions en lot, export ; fiche détaillée par onglets.
 *
 * Lecture avec le rôle `nova_platform` (jamais de jeton de paiement ni de secret). La création manuelle d'un client n'existe pas (un
 * compte naît de l'inscription, qui vérifie l'e-mail et le téléphone du propriétaire) ; l'« impersonation » est exclue par décision du
 * fondateur (CLAUDE.md, règle 3). Les modifications passent par `ClientManager` et `OrganizationLifecycle`, avec audit.
 */
class OrganizationResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = PlatformOrganization::class;

    /** Permission nécessaire pour voir cet écran (docs/38). */
    protected static ?string $viewPermission = 'clients.view';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 1;

    /** Pictogramme et couleur de chaque état d'abonnement : jamais la couleur seule, le libellé est toujours affiché. */
    public const STATE_STYLE = [
        'trial' => ['info', 'heroicon-m-beaker'],
        'active' => ['success', 'heroicon-m-check-circle'],
        'past_due' => ['danger', 'heroicon-m-exclamation-circle'],
        'cancelled' => ['gray', 'heroicon-m-x-circle'],
        'expired' => ['warning', 'heroicon-m-clock'],
        'paused' => ['warning', 'heroicon-m-pause-circle'],
        'none' => ['gray', 'heroicon-m-minus-circle'],
    ];

    public const STATUS_COLOR = [
        Organization::STATUS_ACTIVE => 'success',
        Organization::STATUS_TRIAL => 'info',
        Organization::STATUS_PAST_DUE => 'warning',
        Organization::STATUS_PAUSED => 'warning',
        Organization::STATUS_SUSPENDED => 'danger',
        Organization::STATUS_PENDING_DELETION => 'danger',
        Organization::STATUS_DELETED => 'danger',
    ];

    public static function getModelLabel(): string
    {
        return __('admin.organizations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.organizations.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.organizations.plural');
    }

    /** Pas de création manuelle (voir le descriptif) ; la modification passe par des actions explicites, jamais par un formulaire libre. */
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

    /** La requête de la liste joint d'autres tables (qui ont aussi une colonne « id ») : la clé du client est qualifiée. */
    public static function getRecordRouteKeyName(): ?string
    {
        return 'organizations.id';
    }

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Model> $query */
        $query = ClientDirectory::query();

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Model $record): string => static::getUrl('view', ['record' => $record->getKey()]))
            ->striped()
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([10, 25, 50, 100])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->columns([
                ViewColumn::make('name')->label(__('clients.columns.client'))->view('admin.clients.cell-client')
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('organizations.name', $direction === 'asc' ? 'asc' : 'desc')),
                TextColumn::make('owner_email')->label(__('clients.columns.contact'))->placeholder('—')->limit(30)->description(fn (Model $record): ?string => $record->getAttribute('owner_phone')),
                TextColumn::make('plan_name')->label(__('clients.columns.plan'))->placeholder('—')->badge()->color('gray'),
                TextColumn::make('subscription_state')->label(__('clients.columns.subscription'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("clients.states.{$state}"))
                    ->color(fn (string $state): string => self::STATE_STYLE[$state][0] ?? 'gray')
                    ->icon(fn (string $state): string => self::STATE_STYLE[$state][1] ?? 'heroicon-m-minus-circle'),
                TextColumn::make('renewal_at')->label(__('clients.columns.renewal'))->dateTime('d/m/Y')->placeholder('—')
                    ->description(fn (Model $record): ?string => $record->getAttribute('renewal_at') === null ? null : __('clients.renewal_kind.'.($record->getAttribute('subscription_state') === 'trial' ? 'trial' : 'renewal')))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('renewal_at '.($direction === 'asc' ? 'asc' : 'desc').' nulls last')),
                TextColumn::make('mrr_cents')->label(__('clients.columns.mrr'))->alignEnd()
                    ->state(fn (Model $record): string => (int) $record->getAttribute('mrr_cents') === 0 ? '—' : number_format((int) $record->getAttribute('mrr_cents') / 100, 2, ',', ' ').' '.($record->getAttribute('plan_currency') ?? 'EUR'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('mrr_cents '.($direction === 'asc' ? 'asc' : 'desc'))),
                TextColumn::make('members_count')->label(__('clients.columns.users'))->numeric()->placeholder('0')->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('us.members_count '.($direction === 'asc' ? 'asc' : 'desc').' nulls last')),
                TextColumn::make('created_at')->label(__('clients.columns.created'))->dateTime('d/m/Y')->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('organizations.created_at', $direction === 'asc' ? 'asc' : 'desc')),
                TextColumn::make('last_activity_at')->label(__('clients.columns.activity'))->since()->placeholder(__('clients.never'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('last_activity_at '.($direction === 'asc' ? 'asc' : 'desc').' nulls last')),
                TextColumn::make('status')->label(__('clients.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.statuses.{$state}"))
                    ->color(fn (string $state): string => self::STATUS_COLOR[$state] ?? 'gray')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('organizations.status', $direction === 'asc' ? 'asc' : 'desc')),
                TextColumn::make('sector')->label(__('clients.columns.sector'))->formatStateUsing(fn (?string $state): ?string => self::sectorLabel($state))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('country')->label(__('clients.columns.country'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tag_list')->label(__('clients.columns.tags'))->badge()->separator(',')->color('gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('subscription')->label(__('clients.columns.subscription'))->multiple()
                    ->options(fn (): array => collect(ClientDirectory::STATES)->mapWithKeys(fn (string $state): array => [$state => __("clients.states.{$state}")])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['values'] ?? [], fn (Builder $q, array $values) => $q->whereRaw('('.ClientDirectory::STATE_SQL.') in ('.implode(',', array_fill(0, count($values), '?')).')', array_values($values)))),
                SelectFilter::make('plan')->label(__('clients.columns.plan'))
                    ->options(fn (): array => DB::connection(PlatformAudit::CONNECTION)->table('plans')->orderBy('position')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $id) => $q->whereRaw('pl.id = ?', [$id]))),
                SelectFilter::make('trial')->label(__('clients.filters.trial'))
                    ->options(['in_trial' => __('clients.filters.in_trial'), 'ending_soon' => __('clients.filters.ending_soon'), 'expired' => __('clients.states.expired')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'in_trial' => $query->whereRaw('('.ClientDirectory::STATE_SQL.") = 'trial'"),
                        'ending_soon' => $query->whereRaw('('.ClientDirectory::STATE_SQL.") = 'trial'")->whereBetween('organizations.trial_ends_at', [now(), now()->addDays(7)]),
                        'expired' => $query->whereRaw('('.ClientDirectory::STATE_SQL.") = 'expired'"),
                        default => $query,
                    }),
                Filter::make('renewal')->label(__('clients.filters.renewal'))
                    ->schema([DatePicker::make('from')->label(__('clients.filters.from'))->native(false)->displayFormat('d/m/Y'), DatePicker::make('until')->label(__('clients.filters.until'))->native(false)->displayFormat('d/m/Y')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->whereRaw('('.str_replace('{state}', ClientDirectory::STATE_SQL, ClientDirectory::RENEWAL_SQL).') >= ?', [$date.' 00:00:00']))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->whereRaw('('.str_replace('{state}', ClientDirectory::STATE_SQL, ClientDirectory::RENEWAL_SQL).') <= ?', [$date.' 23:59:59']))),
                SelectFilter::make('payment')->label(__('clients.filters.payment'))
                    ->options(['past_due' => __('clients.filters.payment_past_due'), 'failed' => __('clients.filters.payment_failed'), 'no_method' => __('clients.filters.payment_no_method')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'past_due' => $query->whereRaw('('.ClientDirectory::STATE_SQL.") = 'past_due'"),
                        'failed' => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('payments')->whereColumn('payments.org_id', 'organizations.id')->where('payments.status', 'failed')->where('payments.created_at', '>=', now()->subDays(30))),
                        'no_method' => $query->whereNotNull('sub.id')->whereNull('sub.payment_method_id'),
                        default => $query,
                    }),
                SelectFilter::make('status')->label(__('clients.columns.status'))->multiple()
                    ->options(fn (): array => collect([Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_SUSPENDED, Organization::STATUS_PAUSED, Organization::STATUS_PENDING_DELETION])->mapWithKeys(fn (string $status): array => [$status => __("admin.statuses.{$status}")])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['values'] ?? [], fn (Builder $q, array $values) => $q->whereIn('organizations.status', $values))),
                SelectFilter::make('country')->label(__('clients.columns.country'))->multiple()
                    ->options(fn (): array => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->whereNull('deleted_at')->distinct()->orderBy('country')->pluck('country', 'country')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['values'] ?? [], fn (Builder $q, array $values) => $q->whereIn('organizations.country', $values))),
                Filter::make('created')->label(__('clients.columns.created'))
                    ->schema([DatePicker::make('from')->label(__('clients.filters.from'))->native(false)->displayFormat('d/m/Y'), DatePicker::make('until')->label(__('clients.filters.until'))->native(false)->displayFormat('d/m/Y')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where('organizations.created_at', '>=', $date.' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where('organizations.created_at', '<=', $date.' 23:59:59'))),
                SelectFilter::make('activity')->label(__('clients.filters.activity'))
                    ->options(['active_7' => __('clients.filters.active_7'), 'inactive_30' => __('clients.filters.inactive_30'), 'never' => __('clients.filters.never')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active_7' => $query->whereRaw(ClientDirectory::ACTIVITY_SQL.' >= ?', [now()->subDays(7)]),
                        'inactive_30' => $query->where(fn (Builder $q) => $q->whereRaw(ClientDirectory::ACTIVITY_SQL.' < ?', [now()->subDays(30)])->orWhereRaw(ClientDirectory::ACTIVITY_SQL.' is null')),
                        'never' => $query->whereRaw(ClientDirectory::ACTIVITY_SQL.' is null'),
                        default => $query,
                    }),
                Filter::make('users')->label(__('clients.filters.users'))
                    ->schema([TextInput::make('min')->label(__('clients.filters.min'))->numeric()->integer()->minValue(0), TextInput::make('max')->label(__('clients.filters.max'))->numeric()->integer()->minValue(0)])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(($data['min'] ?? '') !== '' && $data['min'] !== null, fn (Builder $q) => $q->whereRaw('coalesce(us.members_count, 0) >= ?', [(int) $data['min']]))
                        ->when(($data['max'] ?? '') !== '' && $data['max'] !== null, fn (Builder $q) => $q->whereRaw('coalesce(us.members_count, 0) <= ?', [(int) $data['max']]))),
                SelectFilter::make('tag')->label(__('clients.filters.tag'))
                    ->options(fn (): array => DB::connection(PlatformAudit::CONNECTION)->table('client_tags')->distinct()->orderBy('tag')->pluck('tag', 'tag')->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $tag) => $q->whereExists(fn ($sub) => $sub->selectRaw('1')->from('client_tags')->whereColumn('client_tags.org_id', 'organizations.id')->where('client_tags.tag', $tag)))),
            ])
            ->recordActions([
                Action::make('view')->label(__('clients.actions.view'))->icon('heroicon-m-eye')->url(fn (Model $record): string => static::getUrl('view', ['record' => $record->getKey()])),
                ClientActions::editDetails(),
                ClientActions::extendTrial(),
                ClientActions::tag(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkExtendTrial(), self::bulkLifecycle('suspend'), self::bulkLifecycle('lift'),
                    self::bulkTag(true), self::bulkTag(false),
                    BulkAction::make('export')->visible(fn (): bool => AdminAccess::can('clients.export'))->label(__('clients.actions.export_selected'))->icon('heroicon-m-arrow-down-tray')->deselectRecordsAfterCompletion()
                        ->schema(ClientActions::exportFields())->modalHeading(__('clients.export.heading'))
                        ->modalDescription(fn (Collection $records): string => __('clients.export.description', ['count' => $records->count()]))->modalSubmitActionLabel(__('clients.export.confirm'))
                        ->action(fn (Collection $records, array $data) => ClientActions::download(ClientDirectory::query()->whereIn('organizations.id', $records->pluck('id')->all()), (array) ($data['groups'] ?? []), [], 'selected')),
                ])->label(__('clients.bulk.label')),
            ])
            ->emptyStateHeading(__('clients.empty.heading'))
            ->emptyStateDescription(__('clients.empty.description'))
            ->emptyStateIcon('heroicon-o-building-office-2');
    }

    /**
     * Recherche : nom, adresse, identifiant, contact (e-mail, téléphone) ou identifiant d'abonnement.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function search(Builder $query, string $search): Builder
    {
        $like = '%'.addcslashes(trim($search), '\\%_').'%';

        return $query->where(function (Builder $inner) use ($like): void {
            $inner->where('organizations.name', 'ilike', $like)
                ->orWhere('organizations.slug', 'ilike', $like)
                ->orWhere('organizations.billing_name', 'ilike', $like)
                ->orWhereRaw('organizations.id::text ilike ?', [$like])
                ->orWhereRaw('us.owner_email::text ilike ?', [$like])
                ->orWhereRaw('us.owner_phone ilike ?', [$like])
                ->orWhereRaw('sub.id::text ilike ?', [$like]);
        });
    }

    private static function bulkExtendTrial(): BulkAction
    {
        return BulkAction::make('extend_trial')->visible(fn (): bool => AdminAccess::can('subscriptions.edit'))->label(__('clients.actions.extend_trial'))->icon('heroicon-m-clock')->deselectRecordsAfterCompletion()
            ->modalHeading(__('clients.trial.bulk_heading'))->modalDescription(__('clients.trial.bulk_description'))
            ->schema(ClientActions::trialFields())
            ->action(fn (Collection $records, array $data) => ClientActions::bulkResult(app(ClientManager::class)->bulk(ClientActions::admin(), $records->pluck('id')->all(), 'extend_trial', fn (PlatformOrganization $org) => app(ClientManager::class)->extendTrial(ClientActions::admin(), $org, (int) $data['days'], ClientActions::reason($data)))));
    }

    /** @param  'suspend'|'lift'  $method */
    private static function bulkLifecycle(string $method): BulkAction
    {
        return BulkAction::make($method)->visible(fn (): bool => AdminAccess::can('clients.suspend'))->label(__("clients.actions.{$method}"))->icon($method === 'suspend' ? 'heroicon-m-no-symbol' : 'heroicon-m-check-badge')->color($method === 'suspend' ? 'danger' : 'success')->deselectRecordsAfterCompletion()
            ->requiresConfirmation()->modalHeading(__("admin.lifecycle.{$method}.heading"))->modalDescription(__("admin.lifecycle.{$method}.description"))
            ->schema(SensitiveActionFields::make())
            ->action(fn (Collection $records, array $data) => ClientActions::bulkResult(app(ClientManager::class)->bulk(ClientActions::admin(), $records->pluck('id')->all(), $method, fn (PlatformOrganization $org) => app(OrganizationLifecycle::class)->{$method}($org, ClientActions::admin()->id, ClientActions::reason($data)))));
    }

    private static function bulkTag(bool $add): BulkAction
    {
        return BulkAction::make($add ? 'tag_add' : 'tag_remove')->visible(fn (): bool => AdminAccess::can('clients.edit'))->label(__($add ? 'clients.actions.tag_add' : 'clients.actions.tag_remove'))->icon($add ? 'heroicon-m-tag' : 'heroicon-m-minus-circle')->deselectRecordsAfterCompletion()
            ->schema([TextInput::make('tag')->label(__('clients.tag'))->required()->maxLength(30)])
            ->action(fn (Collection $records, array $data) => ClientActions::bulkResult(app(ClientManager::class)->bulk(ClientActions::admin(), $records->pluck('id')->all(), $add ? 'tag_add' : 'tag_remove', fn (PlatformOrganization $org) => $add ? app(ClientManager::class)->addTag(ClientActions::admin(), $org, (string) $data['tag']) : app(ClientManager::class)->removeTag(ClientActions::admin(), $org, (string) $data['tag']))));
    }

    /** Nom du secteur dans la langue de l'interface (le code brut si le secteur n'existe plus). */
    public static function sectorLabel(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        // Une seule lecture par requête (cache en mémoire), au lieu d'une par ligne du tableau.
        $locale = app()->getLocale();
        $labels = Cache::store('array')->rememberForever("sector-labels:{$locale}", fn (): array => PlatformSector::query()->get()->mapWithKeys(fn (PlatformSector $sector): array => [$sector->code => $sector->label($locale)])->all());

        return $labels[$code] ?? $code;
    }

    /**
     * Fiche d'un client, par onglets : vue d'ensemble, informations et équipe, abonnement, usage et santé, notes. Les onglets Factures,
     * Activité et Support sont des tableaux (gestionnaires de relation) sous cette fiche.
     */
    public static function infolist(Schema $schema): Schema
    {
        $data = fn (Model $record): array => ['org' => $record, 'profile' => app(ClientProfile::class)];

        return $schema->components([
            Section::make(__('admin.organizations.account'))->columnSpanFull()->columns(['default' => 1, 'md' => 3])->schema([
                TextEntry::make('name')->label(__('admin.organizations.name')),
                TextEntry::make('status')->label(__('admin.organizations.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.statuses.{$state}"))->color(fn (string $state): string => self::STATUS_COLOR[$state] ?? 'gray'),
                TextEntry::make('subscription_state')->label(__('clients.columns.subscription'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("clients.states.{$state}"))
                    ->color(fn (string $state): string => self::STATE_STYLE[$state][0] ?? 'gray')->icon(fn (string $state): string => self::STATE_STYLE[$state][1] ?? 'heroicon-m-minus-circle'),
                TextEntry::make('plan_name')->label(__('clients.columns.plan'))->placeholder('—'),
                TextEntry::make('owner_email')->label(__('clients.columns.contact'))->placeholder('—'),
                TextEntry::make('id')->label(__('clients.columns.id'))->copyable(),
                TextEntry::make('suspension_reason')->label(__('admin.organizations.suspension_reason'))->placeholder('—'),
                TextEntry::make('suspended_at')->label(__('admin.organizations.suspended_at'))->dateTime('d/m/Y H:i T', fn (?Model $record): string => (string) ($record?->getAttribute('timezone') ?: 'UTC'))->placeholder('—'),
                TextEntry::make('deletion_scheduled_for')->label(__('admin.organizations.deletion_scheduled_for'))->dateTime('d/m/Y')->placeholder('—'),
            ]),
            Tabs::make('client')->columnSpanFull()->tabs([
                Tab::make(__('clients.tabs.overview'))->icon('heroicon-m-squares-2x2')->schema([ViewEntry::make('overview')->hiddenLabel()->view('admin.clients.overview')->viewData($data)]),
                Tab::make(__('clients.tabs.information'))->icon('heroicon-m-identification')->schema([ViewEntry::make('information')->hiddenLabel()->view('admin.clients.information')->viewData($data)]),
                Tab::make(__('clients.tabs.subscription'))->icon('heroicon-m-credit-card')->schema([ViewEntry::make('subscription')->hiddenLabel()->view('admin.clients.subscription')->viewData($data)]),
                Tab::make(__('clients.tabs.usage'))->icon('heroicon-m-chart-bar')->schema([ViewEntry::make('usage')->hiddenLabel()->view('admin.clients.usage')->viewData($data)]),
                Tab::make(__('clients.tabs.notes'))->icon('heroicon-m-pencil-square')->schema([ViewEntry::make('notes')->hiddenLabel()->view('admin.clients.notes')->viewData($data)]),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [InvoicesRelationManager::class, ActivityRelationManager::class, TicketsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
        ];
    }
}
