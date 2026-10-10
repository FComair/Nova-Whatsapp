<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\SupportTicketResource\Pages\ListSupportTickets;
use App\Modules\Admin\Filament\Resources\SupportTicketResource\Pages\ViewSupportTicket;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\PlatformSettings;
use App\Modules\Admin\Services\SupportConsole;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\SupportSla;
use App\Modules\Support\Support\TicketStatus;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
 * File d'attente des tickets de support (docs/29) : liste dense, recherche, filtres, vues, actions en lot ; le détail est
 * `ViewSupportTicket`. Lit et écrit avec le rôle `nova_platform` ; un agent de support ne voit que ses tickets et les non assignés.
 */
class SupportTicketResource extends Resource
{
    protected static ?string $model = PlatformSupportTicket::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'support-tickets';

    /** Pictogramme et couleur de chaque état : jamais la couleur seule, le libellé est toujours affiché. */
    public const STATUS_STYLE = [
        TicketStatus::NEW => ['info', 'heroicon-m-sparkles'],
        TicketStatus::OPEN => ['gray', 'heroicon-m-inbox'],
        TicketStatus::IN_PROGRESS => ['primary', 'heroicon-m-arrow-path'],
        TicketStatus::WAITING_ON_YOU => ['warning', 'heroicon-m-clock'],
        TicketStatus::WAITING_INTERNAL => ['warning', 'heroicon-m-user-group'],
        TicketStatus::RESOLVED => ['success', 'heroicon-m-check-circle'],
        TicketStatus::CLOSED => ['gray', 'heroicon-m-lock-closed'],
        TicketStatus::REOPENED => ['danger', 'heroicon-m-arrow-uturn-left'],
    ];

    public const PRIORITY_STYLE = [
        'low' => ['gray', 'heroicon-m-arrow-down'],
        'medium' => ['info', 'heroicon-m-minus'],
        'high' => ['warning', 'heroicon-m-arrow-up'],
        'urgent' => ['danger', 'heroicon-m-exclamation-triangle'],
    ];

    public static function getModelLabel(): string
    {
        return __('tickets.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('tickets.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('tickets.navigation.tickets');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('tickets.navigation.group');
    }

    /** Pastille du menu : tickets qui attendent une réponse de l'équipe (dans la portée de l'agent). */
    public static function getNavigationBadge(): ?string
    {
        $admin = SupportAccess::admin();

        if ($admin === null) {
            return null;
        }

        $count = SupportAccess::scope(PlatformSupportTicket::query(), $admin)->whereIn('status', TicketStatus::ACTIVE)->whereRaw(self::NEEDS_RESPONSE_SQL)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('tickets.navigation.badge');
    }

    public static function canCreate(): bool
    {
        return true;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Le dernier mot est au client (ou l'équipe n'a jamais répondu). */
    public const NEEDS_RESPONSE_SQL = '(support_tickets.last_client_message_at is not null and (support_tickets.last_staff_message_at is null or support_tickets.last_client_message_at > support_tickets.last_staff_message_at))';

    /** Rang de la priorité effective, pour trier. */
    private const PRIORITY_RANK_SQL = "case coalesce(support_tickets.staff_priority, support_tickets.urgency) when 'urgent' then 4 when 'high' then 3 when 'medium' then 2 else 1 end";

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        $admin = SupportAccess::admin();
        $query = parent::getEloquentQuery();

        if ($admin !== null) {
            SupportAccess::scope($query, $admin);
        }

        return $query
            ->leftJoin('organizations as org', 'org.id', '=', 'support_tickets.org_id')
            ->leftJoin('platform_admins as agent', 'agent.id', '=', 'support_tickets.assigned_admin_id')
            ->select('support_tickets.*', 'org.name as organization_name', 'agent.email as assignee_email')
            ->addSelect(DB::raw(self::PRIORITY_RANK_SQL.' as priority_rank'))
            ->addSelect(DB::raw('(select string_agg(t.tag, \',\' order by t.tag) from support_ticket_tags t where t.ticket_id = support_tickets.id) as tag_list'));
    }

    /** Contrôle d'accès de la page de détail : un agent n'ouvre pas le ticket d'un collègue. */
    public static function canView(Model $record): bool
    {
        $admin = SupportAccess::admin();

        return $admin !== null && ($record instanceof SupportTicket) && (! $admin->isSupportAgent() || $record->assigned_admin_id === null || $record->assigned_admin_id === $admin->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->poll('60s')
            ->recordUrl(fn (Model $record): string => static::getUrl('view', ['record' => $record]))
            ->striped()
            ->defaultPaginationPageOption(25)
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->columns([
                TextColumn::make('reference_number')->label(__('tickets.columns.id'))
                    ->formatStateUsing(fn (int $state): string => 'NWS-'.$state)->weight('bold')->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::search($query, $search)),
                TextColumn::make('attention')->label(__('tickets.columns.attention'))->badge()
                    ->state(fn (Model $record): ?string => self::attention($record))
                    ->formatStateUsing(fn (string $state): string => __("tickets.attention.{$state}"))
                    ->icon(fn (string $state): string => ['overdue' => 'heroicon-m-exclamation-circle', 'at_risk' => 'heroicon-m-clock', 'needs_response' => 'heroicon-m-chat-bubble-left-ellipsis', 'no_reply' => 'heroicon-m-bell-alert'][$state])
                    ->color(fn (string $state): string => ['overdue' => 'danger', 'at_risk' => 'warning', 'needs_response' => 'info', 'no_reply' => 'warning'][$state]),
                TextColumn::make('subject')->label(__('tickets.columns.subject'))->limit(60)->tooltip(fn (Model $record): string => (string) $record->getAttribute('subject'))->wrap()->extraCellAttributes(['style' => 'min-width: 14rem']),
                TextColumn::make('organization_name')->label(__('tickets.columns.client'))->placeholder('—')->limit(28),
                TextColumn::make('reply_email')->label(__('tickets.columns.requester'))->toggleable()->limit(28),
                TextColumn::make('status')->label(__('tickets.columns.status'))->badge()->sortable()
                    ->formatStateUsing(fn (string $state): string => __("tickets.statuses.{$state}"))
                    ->color(fn (string $state): string => self::STATUS_STYLE[$state][0] ?? 'gray')
                    ->icon(fn (string $state): string => self::STATUS_STYLE[$state][1] ?? 'heroicon-m-question-mark-circle'),
                TextColumn::make('priority_rank')->label(__('tickets.columns.priority'))->badge()
                    ->state(fn (Model $record): string => $record instanceof SupportTicket ? $record->priority() : 'medium')
                    ->formatStateUsing(fn (string $state): string => __("tickets.priorities.{$state}"))
                    ->color(fn (string $state): string => self::PRIORITY_STYLE[$state][0] ?? 'gray')
                    ->icon(fn (string $state): string => self::PRIORITY_STYLE[$state][1] ?? 'heroicon-m-minus')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByRaw('priority_rank '.($direction === 'asc' ? 'asc' : 'desc'))),
                TextColumn::make('category')->label(__('tickets.columns.category'))->formatStateUsing(fn (string $state): string => __("tickets.categories.{$state}"))->toggleable(),
                TextColumn::make('assignee_email')->label(__('tickets.columns.assignee'))->placeholder(__('tickets.unassigned'))->limit(24),
                TextColumn::make('created_at')->label(__('tickets.columns.created'))->dateTime('d/m/Y H:i')->sortable()->toggleable(),
                TextColumn::make('last_message_at')->label(__('tickets.columns.updated'))->since()->description(fn (Model $record): string => $record instanceof SupportTicket ? __('tickets.waiting_since.'.($record->needsResponse() ? 'client' : 'team')) : '')->sortable(),
                TextColumn::make('last_client_message_at')->label(__('tickets.columns.waiting'))->since()->placeholder('—')->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('source')->label(__('tickets.columns.source'))->formatStateUsing(fn (string $state): string => __("tickets.sources.{$state}"))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tag_list')->label(__('tickets.columns.tags'))->badge()->separator(',')->color('gray')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('tickets.columns.status'))->multiple()
                    ->options(fn (): array => collect(TicketStatus::ALL)->mapWithKeys(fn (string $status): array => [$status => __("tickets.statuses.{$status}")])->all()),
                SelectFilter::make('priority')->label(__('tickets.columns.priority'))->multiple()
                    ->options(fn (): array => collect(SupportTicket::PRIORITIES)->mapWithKeys(fn (string $priority): array => [$priority => __("tickets.priorities.{$priority}")])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['values'] ?? [], fn (Builder $q, array $values) => $q->whereRaw('coalesce(support_tickets.staff_priority, support_tickets.urgency) in ('.implode(',', array_fill(0, count($values), '?')).')', array_values($values)))),
                SelectFilter::make('category')->label(__('tickets.columns.category'))->multiple()
                    ->options(fn (): array => collect(SupportTicket::CATEGORIES)->mapWithKeys(fn (string $category): array => [$category => __("tickets.categories.{$category}")])->all()),
                SelectFilter::make('assignee')->label(__('tickets.columns.assignee'))
                    ->options(fn (): array => ['none' => __('tickets.unassigned'), ...SupportAccess::assignees()])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'none' => $query->whereNull('support_tickets.assigned_admin_id'),
                        default => $query->where('support_tickets.assigned_admin_id', $data['value']),
                    }),
                SelectFilter::make('organization')->label(__('tickets.columns.client'))->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%')->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn (string $value): ?string => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->where('id', $value)->value('name'))
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $id) => $q->where('support_tickets.org_id', $id))),
                SelectFilter::make('source')->label(__('tickets.columns.source'))->multiple()
                    ->options(fn (): array => collect(SupportTicket::SOURCES)->mapWithKeys(fn (string $source): array => [$source => __("tickets.sources.{$source}")])->all()),
                Filter::make('created')->label(__('tickets.columns.created'))
                    ->schema([DatePicker::make('from')->label(__('tickets.filters.from'))->native(false)->displayFormat('d/m/Y'), DatePicker::make('until')->label(__('tickets.filters.until'))->native(false)->displayFormat('d/m/Y')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where('support_tickets.created_at', '>=', $date.' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where('support_tickets.created_at', '<=', $date.' 23:59:59'))),
                Filter::make('needs_response')->label(__('tickets.filters.needs_response'))->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereIn('support_tickets.status', TicketStatus::ACTIVE)->whereRaw(self::NEEDS_RESPONSE_SQL)),
                SelectFilter::make('sla')->label(__('tickets.filters.sla'))
                    ->options(fn (): array => ['overdue' => __('tickets.attention.overdue'), 'at_risk' => __('tickets.attention.at_risk')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'overdue' => self::overdue($query),
                        'at_risk' => self::atRisk($query),
                        default => $query,
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkAssign(), self::bulkStatus(), self::bulkPriority(), self::bulkTag(), self::bulkClose(),
                ])->label(__('tickets.bulk.label')),
            ])
            ->emptyStateHeading(__('tickets.empty.heading'))
            ->emptyStateDescription(__('tickets.empty.description'))
            ->emptyStateIcon('heroicon-o-lifebuoy');
    }

    /**
     * Recherche : numéro, objet, compte client, demandeur, étiquette ou texte d'un message (notes internes comprises : l'équipe les lit).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function search(Builder $query, string $search): Builder
    {
        $like = '%'.addcslashes(trim($search), '\\%_').'%';
        $number = SupportTicket::numberFrom($search);

        return $query->where(function (Builder $inner) use ($like, $number): void {
            $inner->where('support_tickets.subject', 'ilike', $like)
                ->orWhere('support_tickets.reply_email', 'ilike', $like)
                ->orWhere('org.name', 'ilike', $like)
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('support_ticket_tags')->whereColumn('support_ticket_tags.ticket_id', 'support_tickets.id')->where('support_ticket_tags.tag', 'ilike', $like))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('support_messages')->whereColumn('support_messages.ticket_id', 'support_tickets.id')->where('support_messages.body', 'ilike', $like));

            if ($number !== null) {
                $inner->orWhere('support_tickets.reference_number', $number);
            }
        });
    }

    /**
     * Tickets sans première réponse dont l'échéance est passée.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function overdue(Builder $query): Builder
    {
        return $query->whereIn('support_tickets.status', TicketStatus::ACTIVE)->whereNull('support_tickets.first_response_at')->where('support_tickets.first_response_due_at', '<=', now());
    }

    /**
     * Tickets sans première réponse dont la part de délai `support_at_risk_percent` est écoulée, mais pas encore en retard.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function atRisk(Builder $query): Builder
    {
        $percent = app(PlatformSettings::class)->integer('support_at_risk_percent');

        return $query->whereIn('support_tickets.status', TicketStatus::ACTIVE)->whereNull('support_tickets.first_response_at')->where('support_tickets.first_response_due_at', '>', now())
            ->whereRaw('extract(epoch from (?::timestamptz - support_tickets.created_at)) * 100 >= extract(epoch from (support_tickets.first_response_due_at - support_tickets.created_at)) * ?', [now()->toDateTimeString(), $percent]);
    }

    /** Signal d'attention d'une ligne : échéance dépassée > à risque > le client attend une réponse > aucune première réponse. */
    private static function attention(Model $record): ?string
    {
        if (! $record instanceof SupportTicket) {
            return null;
        }

        return match (app(SupportSla::class)->stateOf($record)) {
            SupportSla::OVERDUE => 'overdue',
            SupportSla::AT_RISK => 'at_risk',
            default => $record->needsResponse() ? 'needs_response' : null,
        };
    }

    private static function bulkAssign(): BulkAction
    {
        return BulkAction::make('assign')->label(__('tickets.bulk.assign'))->icon('heroicon-m-user-plus')->deselectRecordsAfterCompletion()
            ->schema([Select::make('agent')->label(__('tickets.columns.assignee'))->options(fn (): array => ['none' => __('tickets.unassigned'), ...SupportAccess::assignees()])->required()])
            ->action(fn (Collection $records, array $data) => self::runBulk($records, 'assign', fn (SupportConsole $console, $admin, $ticket) => $console->assign($admin, $ticket, $data['agent'] === 'none' ? null : (string) $data['agent'])));
    }

    private static function bulkStatus(): BulkAction
    {
        return BulkAction::make('status')->label(__('tickets.bulk.status'))->icon('heroicon-m-arrow-path')->deselectRecordsAfterCompletion()
            ->schema([Select::make('status')->label(__('tickets.columns.status'))->options(fn (): array => collect(TicketStatus::STAFF_SETTABLE)->mapWithKeys(fn (string $status): array => [$status => __("tickets.statuses.{$status}")])->all())->required()])
            ->action(fn (Collection $records, array $data) => self::runBulk($records, 'status', fn (SupportConsole $console, $admin, $ticket) => $console->setStatus($admin, $ticket, (string) $data['status'])));
    }

    private static function bulkPriority(): BulkAction
    {
        return BulkAction::make('priority')->label(__('tickets.bulk.priority'))->icon('heroicon-m-flag')->deselectRecordsAfterCompletion()
            ->schema([Select::make('priority')->label(__('tickets.columns.priority'))->options(fn (): array => collect(SupportTicket::PRIORITIES)->mapWithKeys(fn (string $priority): array => [$priority => __("tickets.priorities.{$priority}")])->all())->required()])
            ->action(fn (Collection $records, array $data) => self::runBulk($records, 'priority', fn (SupportConsole $console, $admin, $ticket) => $console->setPriority($admin, $ticket, (string) $data['priority'])));
    }

    private static function bulkTag(): BulkAction
    {
        return BulkAction::make('tag')->label(__('tickets.bulk.tag'))->icon('heroicon-m-tag')->deselectRecordsAfterCompletion()
            ->schema([TextInput::make('tag')->label(__('tickets.tag'))->maxLength(30)->required()])
            ->action(fn (Collection $records, array $data) => self::runBulk($records, 'tag', fn (SupportConsole $console, $admin, $ticket) => $console->addTag($admin, $ticket, (string) $data['tag'])));
    }

    private static function bulkClose(): BulkAction
    {
        return BulkAction::make('close')->label(__('tickets.bulk.close'))->icon('heroicon-m-lock-closed')->color('danger')->deselectRecordsAfterCompletion()
            ->requiresConfirmation()->modalHeading(__('tickets.bulk.close_heading'))->modalDescription(__('tickets.bulk.close_description'))
            ->action(fn (Collection $records) => self::runBulk($records, 'close', fn (SupportConsole $console, $admin, $ticket) => $console->close($admin, $ticket)));
    }

    /**
     * @param  Collection<int, Model>  $records
     * @param  callable(SupportConsole, PlatformAdmin, PlatformSupportTicket): mixed  $operation
     */
    private static function runBulk(Collection $records, string $name, callable $operation): void
    {
        $admin = SupportAccess::admin() ?? abort(403);
        $console = app(SupportConsole::class);

        $result = $console->bulk($admin, $records->pluck('id')->all(), $name, fn (PlatformSupportTicket $ticket) => $operation($console, $admin, $ticket));

        $notification = Notification::make()->title(__('tickets.bulk.done', ['count' => $result['done']]));
        $result['skipped'] > 0 ? $notification->warning()->body(__('tickets.bulk.skipped', ['count' => $result['skipped']])) : $notification->success();
        $notification->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportTickets::route('/'),
            'view' => ViewSupportTicket::route('/{record}'),
        ];
    }
}
