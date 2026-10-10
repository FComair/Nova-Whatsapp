<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\OrganizationResource\RelationManagers;

use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Services\ClientProfile;
use App\Modules\Admin\Services\SupportConsole;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Support\TicketStatus;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Support dans la fiche d'un client (docs/31 §9) : tickets ouverts et résolus, priorité, état, agent, dernière activité, lien vers le
 * ticket, et création d'un ticket au nom d'un membre du compte. Visible seulement pour qui peut utiliser les tickets.
 */
class TicketsRelationManager extends RelationManager
{
    protected static string $relationship = 'supportTickets';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('clients.tickets.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return SupportAccess::canUseTickets();
    }

    public function table(Table $table): Table
    {
        $agents = SupportAccess::names();

        return $table
            ->defaultSort('last_message_at', 'desc')
            ->heading(__('clients.tickets.title'))
            ->emptyStateHeading(__('clients.tickets.empty'))
            ->recordUrl(fn (Model $record): string => SupportTicketResource::getUrl('view', ['record' => $record->getKey()]))
            ->columns([
                TextColumn::make('reference_number')->label(__('tickets.columns.id'))->formatStateUsing(fn (int $state): string => 'NWS-'.$state)->weight('semibold')->sortable(),
                TextColumn::make('subject')->label(__('tickets.columns.subject'))->limit(50)->wrap(),
                TextColumn::make('status')->label(__('tickets.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("tickets.statuses.{$state}"))
                    ->color(fn (string $state): string => SupportTicketResource::STATUS_STYLE[$state][0] ?? 'gray')
                    ->icon(fn (string $state): string => SupportTicketResource::STATUS_STYLE[$state][1] ?? 'heroicon-m-minus-circle'),
                TextColumn::make('urgency')->label(__('tickets.columns.priority'))->badge()
                    ->state(fn (Model $record): string => $record instanceof SupportTicket ? $record->priority() : 'medium')
                    ->formatStateUsing(fn (string $state): string => __("tickets.priorities.{$state}"))
                    ->color(fn (string $state): string => SupportTicketResource::PRIORITY_STYLE[$state][0] ?? 'gray')
                    ->icon(fn (string $state): string => SupportTicketResource::PRIORITY_STYLE[$state][1] ?? 'heroicon-m-minus'),
                TextColumn::make('assigned_admin_id')->label(__('tickets.columns.assignee'))->placeholder(__('tickets.unassigned'))->formatStateUsing(fn (?string $state): string => (string) ($agents[$state] ?? __('tickets.unassigned'))),
                TextColumn::make('last_message_at')->label(__('tickets.columns.updated'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')->label(__('tickets.columns.status'))
                    ->options(['open' => __('clients.tickets.open'), 'done' => __('clients.tickets.done')])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->whereIn('status', TicketStatus::ACTIVE),
                        'done' => $query->whereIn('status', [TicketStatus::RESOLVED, TicketStatus::CLOSED]),
                        default => $query,
                    }),
            ])
            ->headerActions([$this->createAction()]);
    }

    private function createAction(): Action
    {
        return Action::make('create')->label(__('clients.tickets.create'))->icon('heroicon-m-plus')->modalHeading(__('clients.tickets.create'))
            ->schema([
                Select::make('user_id')->label(__('tickets.create.requester'))->required()->native(false)->options(function (): array {
                    $owner = $this->getOwnerRecord();

                    return $owner instanceof PlatformOrganization
                        ? collect(app(ClientProfile::class)->members($owner))->where('member_status', 'active')->mapWithKeys(fn (object $m): array => [$m->user_id => $m->email])->all()
                        : [];
                }),
                TextInput::make('subject')->label(__('tickets.columns.subject'))->required()->minLength(5)->maxLength(120),
                Select::make('category')->label(__('tickets.columns.category'))->required()->native(false)->default('other')
                    ->options(fn (): array => collect(SupportTicket::CATEGORIES)->mapWithKeys(fn (string $c): array => [$c => __("tickets.categories.{$c}")])->all()),
                Select::make('priority')->label(__('tickets.columns.priority'))->required()->native(false)->default('medium')
                    ->options(fn (): array => collect(SupportTicket::PRIORITIES)->mapWithKeys(fn (string $p): array => [$p => __("tickets.priorities.{$p}")])->all()),
                Textarea::make('description')->label(__('tickets.create.description'))->required()->rows(5)->maxLength(5000),
            ])
            ->action(function (array $data): void {
                $admin = SupportAccess::admin() ?? abort(403);
                abort_unless(SupportAccess::canUseTickets($admin), 403);
                $ticket = app(SupportConsole::class)->create($admin, [...$data, 'org_id' => $this->getOwnerRecord()->getKey(), 'source' => 'manual']);

                Notification::make()->success()->title(__('tickets.create.done', ['reference' => $ticket->reference()]))->send();
            });
    }
}
