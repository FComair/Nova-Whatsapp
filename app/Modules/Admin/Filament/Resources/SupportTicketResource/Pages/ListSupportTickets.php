<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\SupportTicketResource\Pages;

use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\SupportConsole;
use App\Modules\Admin\Services\SupportNotifier;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Support\TicketStatus;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Boîte de réception du support : vues prêtes à l'emploi (onglets avec compteur), vues enregistrées par l'agent, création manuelle,
 * export CSV (Super Admin) et préférences de notification.
 */
class ListSupportTickets extends ListRecords
{
    protected static string $resource = SupportTicketResource::class;

    /** Limite d'un export : au-delà, affiner les filtres. */
    private const EXPORT_LIMIT = 5000;

    public function getTitle(): string
    {
        return __('tickets.plural');
    }

    /**
     * Vues prêtes à l'emploi (docs/29 §3). Chaque onglet ajoute des conditions à la liste ; les filtres de l'agent s'y combinent.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $admin = SupportAccess::admin();
        $mine = $admin->id ?? '';
        $today = now()->startOfDay();
        $count = fn (callable $modify): int => (int) $modify(SupportAccess::scope(PlatformSupportTicket::query(), $admin ?? new PlatformAdmin))->count();

        $views = [
            'all' => [fn (Builder $q) => $q->whereIn('support_tickets.status', TicketStatus::ACTIVE), 'tickets.views.all'],
            'mine' => [fn (Builder $q) => $q->whereIn('support_tickets.status', TicketStatus::ACTIVE)->where('support_tickets.assigned_admin_id', $mine), 'tickets.views.mine'],
            'unassigned' => [fn (Builder $q) => $q->whereIn('support_tickets.status', TicketStatus::ACTIVE)->whereNull('support_tickets.assigned_admin_id'), 'tickets.views.unassigned'],
            'urgent' => [fn (Builder $q) => $q->whereIn('support_tickets.status', TicketStatus::ACTIVE)->whereRaw("coalesce(support_tickets.staff_priority, support_tickets.urgency) = 'urgent'"), 'tickets.views.urgent'],
            'waiting_client' => [fn (Builder $q) => $q->where('support_tickets.status', TicketStatus::WAITING_ON_YOU), 'tickets.views.waiting_client'],
            'due_first_response' => [fn (Builder $q) => $q->where(fn (Builder $inner) => $inner->where(fn (Builder $a) => SupportTicketResource::overdue($a))->orWhere(fn (Builder $b) => SupportTicketResource::atRisk($b))), 'tickets.views.due_first_response'],
            'resolved_today' => [fn (Builder $q) => $q->whereNotNull('support_tickets.resolved_at')->where('support_tickets.resolved_at', '>=', $today), 'tickets.views.resolved_today'],
            'everything' => [fn (Builder $q) => $q, 'tickets.views.everything'],
        ];

        $tabs = [];

        foreach ($views as $key => [$modify, $label]) {
            $tabs[$key] = Tab::make(__($label))
                ->modifyQueryUsing(fn (Builder $query) => $modify($query))
                ->badge($count($modify))
                ->badgeColor(in_array($key, ['urgent', 'due_first_response'], true) ? 'danger' : 'gray');
        }

        return $tabs;
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->createAction(),
            $this->savedViewsAction(),
            $this->exportAction(),
            $this->preferencesAction(),
        ];
    }

    private function createAction(): Action
    {
        return Action::make('create')->label(__('tickets.create.label'))->icon('heroicon-m-plus')->visible(fn (): bool => AdminAccess::can('tickets.create'))->modalHeading(__('tickets.create.heading'))->modalSubmitActionLabel(__('tickets.create.submit'))
            ->schema([
                Select::make('org_id')->label(__('tickets.columns.client'))->required()->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search): array => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%')->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn (string $value): ?string => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->where('id', $value)->value('name'))
                    ->afterStateUpdated(fn (callable $set) => $set('user_id', null)),
                Select::make('user_id')->label(__('tickets.create.requester'))->required()
                    ->options(fn (callable $get): array => $get('org_id') === null ? [] : collect(app(SupportConsole::class)->members((string) $get('org_id')))->mapWithKeys(fn (object $member): array => [$member->user_id => $member->email])->all()),
                TextInput::make('subject')->label(__('tickets.columns.subject'))->required()->maxLength(120),
                Select::make('category')->label(__('tickets.columns.category'))->required()->default('other')
                    ->options(fn (): array => collect(SupportTicket::CATEGORIES)->mapWithKeys(fn (string $category): array => [$category => __("tickets.categories.{$category}")])->all()),
                Select::make('priority')->label(__('tickets.columns.priority'))->required()->default('medium')
                    ->options(fn (): array => collect(SupportTicket::PRIORITIES)->mapWithKeys(fn (string $priority): array => [$priority => __("tickets.priorities.{$priority}")])->all()),
                Select::make('source')->label(__('tickets.columns.source'))->required()->default('manual')
                    ->options(fn (): array => ['manual' => __('tickets.sources.manual'), 'email' => __('tickets.sources.email')]),
                Select::make('assigned_admin_id')->label(__('tickets.columns.assignee'))->options(fn (): array => SupportAccess::assignees())->placeholder(__('tickets.unassigned')),
                Textarea::make('description')->label(__('tickets.create.description'))->required()->rows(5)->maxLength(5000)->helperText(__('tickets.create.description_help')),
            ])
            ->action(function (array $data): void {
                $admin = SupportAccess::admin() ?? abort(403);
                $ticket = app(SupportConsole::class)->create($admin, $data);

                Notification::make()->success()->title(__('tickets.create.done', ['reference' => $ticket->reference()]))->send();
                $this->redirect(SupportTicketResource::getUrl('view', ['record' => $ticket->id]));
            });
    }

    private function savedViewsAction(): ActionGroup
    {
        $admin = SupportAccess::admin();
        $views = $admin === null ? collect() : DB::connection(PlatformAudit::CONNECTION)->table('support_saved_views')->where('admin_id', $admin->id)->orderBy('name')->get();

        $actions = $views->map(fn (object $view): Action => Action::make('saved_view_'.$view->id)->label($view->name)->icon('heroicon-m-bookmark')
            ->action(function () use ($view): void {
                $this->activeTab = 'everything';
                $this->tableFilters = (array) json_decode((string) $view->filters, true);
                $this->handleTableFilterUpdates();
            }))->all();

        $actions[] = Action::make('save_view')->label(__('tickets.views.save'))->icon('heroicon-m-plus-circle')->modalHeading(__('tickets.views.save'))
            ->schema([TextInput::make('name')->label(__('tickets.views.name'))->required()->maxLength(40)])
            ->action(function (array $data): void {
                $admin = SupportAccess::admin() ?? abort(403);
                DB::connection(PlatformAudit::CONNECTION)->table('support_saved_views')->updateOrInsert(
                    ['admin_id' => $admin->id, 'name' => trim((string) $data['name'])],
                    ['id' => (string) Str::uuid7(), 'filters' => json_encode($this->tableFilters ?? [], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()],
                );
                Notification::make()->success()->title(__('tickets.views.saved'))->send();
            });

        if ($views->isNotEmpty()) {
            $actions[] = Action::make('delete_view')->label(__('tickets.views.delete'))->icon('heroicon-m-trash')->color('danger')->modalHeading(__('tickets.views.delete'))
                ->schema([Select::make('view')->label(__('tickets.views.name'))->options($views->pluck('name', 'id')->all())->required()])
                ->action(function (array $data): void {
                    $admin = SupportAccess::admin() ?? abort(403);
                    DB::connection(PlatformAudit::CONNECTION)->table('support_saved_views')->where('admin_id', $admin->id)->where('id', $data['view'])->delete();
                    Notification::make()->success()->title(__('tickets.views.deleted'))->send();
                });
        }

        return ActionGroup::make($actions)->label(__('tickets.views.menu'))->icon('heroicon-m-bookmark')->button()->color('gray');
    }

    /** Export CSV de la liste filtrée : réservé au Super Admin, tracé (qui, filtres, nombre de lignes). */
    private function exportAction(): Action
    {
        return Action::make('export')->label(__('tickets.export.label'))->icon('heroicon-m-arrow-down-tray')->color('gray')
            ->visible(fn (): bool => ($admin = SupportAccess::admin()) !== null && SupportAccess::canExport($admin))
            ->action(function (): StreamedResponse {
                $admin = SupportAccess::admin() ?? abort(403);
                abort_unless(SupportAccess::canExport($admin), 403);

                /** @var Collection<int, PlatformSupportTicket> $rows */
                $rows = $this->getFilteredSortedTableQuery()->limit(self::EXPORT_LIMIT)->get();
                app(SupportConsole::class)->audited($admin, 'support.tickets.exported', $rows->count(), ['tab' => $this->activeTab, 'filters' => $this->tableFilters ?? []]);

                return response()->streamDownload(function () use ($rows): void {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['id', 'subject', 'client', 'requester', 'status', 'priority', 'category', 'assignee', 'source', 'created_at', 'last_update', 'first_response_at', 'resolved_at'], ',', '"', '');

                    foreach ($rows as $row) {
                        fputcsv($out, array_map(self::safeCell(...), [
                            $row->reference(), $row->subject, $row->getAttribute('organization_name'), $row->reply_email, $row->status, $row->priority(), $row->category,
                            $row->getAttribute('assignee_email'), $row->source, $row->created_at->toIso8601String(), $row->last_message_at->toIso8601String(),
                            $row->first_response_at?->toIso8601String(), $row->resolved_at?->toIso8601String(),
                        ]), ',', '"', '');
                    }

                    fclose($out);
                }, 'support-tickets-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
            });
    }

    /** Une cellule commençant par = + - @ serait lue comme une formule par un tableur : on la neutralise. */
    private static function safeCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /** Préférences de notification de l'agent connecté : pour chaque événement, cloche et/ou e-mail. */
    private function preferencesAction(): Action
    {
        return Action::make('preferences')->label(__('tickets.notify.preferences'))->icon('heroicon-m-bell')->color('gray')->modalHeading(__('tickets.notify.preferences'))->modalDescription(__('tickets.notify.preferences_help'))
            ->fillForm(function (): array {
                $admin = SupportAccess::admin() ?? abort(403);
                $notifier = app(SupportNotifier::class);

                return collect(array_keys(SupportNotifier::DEFAULTS))->mapWithKeys(fn (string $event): array => [$event => $notifier->channelsFor($admin, $event)])->all();
            })
            ->schema(fn (): array => collect(array_keys(SupportNotifier::DEFAULTS))->map(fn (string $event) => CheckboxList::make($event)->label(__("tickets.notify.{$event}.label"))->options(['database' => __('tickets.notify.channel_database'), 'mail' => __('tickets.notify.channel_mail')])->columns(2))->all())
            ->action(function (array $data): void {
                $admin = SupportAccess::admin() ?? abort(403);
                $choices = collect(SupportNotifier::DEFAULTS)->mapWithKeys(fn (array $default, string $event): array => [$event => array_values(array_intersect(SupportNotifier::CHANNELS, (array) ($data[$event] ?? [])))])->all();

                $admin->forceFill(['support_notifications' => $choices])->save();
                PlatformAudit::record($admin->id, 'support.notifications.updated', null, 'platform_admin', $admin->id, $choices);
                Notification::make()->success()->title(__('tickets.notify.saved'))->send();
            });
    }
}
