<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\SupportAgentResource\Pages\ListSupportAgents;
use App\Modules\Admin\Filament\Resources\SupportAgentResource\Pages\ViewSupportAgent;
use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Admin\Filament\Support\SupportTeamActions;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\SupportTeam;
use App\Modules\Admin\Support\SupportPermissions;
use App\Modules\Support\Support\TicketStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Équipe de support (docs/32 §2) : liste des agents (statut, tickets assignés / ouverts / résolus, dernière activité), fiche avec charge de
 * travail, création par invitation, modification, suspension, réactivation, retrait, réinitialisation. Réservée au Super Admin : un agent
 * ne voit ni cet écran ni les autres agents. Ne concerne que le rôle « agent » (jamais un Super Admin ni un éditeur de contenu).
 */
class SupportAgentResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = PlatformAdmin::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'support-team';

    public const STATUS_COLORS = [
        PlatformAdmin::STATUS_ACTIVE => 'success',
        PlatformAdmin::STATUS_INVITED => 'info',
        PlatformAdmin::STATUS_SUSPENDED => 'warning',
        PlatformAdmin::STATUS_REMOVED => 'gray',
    ];

    public static function getModelLabel(): string
    {
        return __('team.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('team.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('team.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('tickets.navigation.group');
    }

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
     * Les agents avec leur charge : assignés (non fermés), ouverts (en traitement), résolus ou fermés, et dernière activité.
     *
     * @return Builder<PlatformAdmin>
     */
    public static function getEloquentQuery(): Builder
    {
        $open = "'".implode("','", TicketStatus::ACTIVE)."'";

        return PlatformAdmin::query()
            ->where('platform_admins.support_role', PlatformAdmin::SUPPORT_AGENT)
            ->select('platform_admins.*')
            ->selectRaw("(select count(*) from support_tickets t where t.assigned_admin_id = platform_admins.id and t.status <> 'closed') as assigned_count")
            ->selectRaw("(select count(*) from support_tickets t where t.assigned_admin_id = platform_admins.id and t.status in ({$open})) as open_count")
            ->selectRaw("(select count(*) from support_tickets t where t.assigned_admin_id = platform_admins.id and t.status in ('resolved','closed')) as resolved_count")
            ->selectRaw('greatest(platform_admins.last_login_at, (select max(a.created_at) from audit_logs a where a.actor_id = platform_admins.id)) as last_activity_at');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('team.fields.name'))->searchable(['platform_admins.name', 'platform_admins.email'])->sortable()->weight('medium')->description(fn (PlatformAdmin $record): string => $record->email)->placeholder('—'),
                TextColumn::make('role')->label(__('team.fields.role'))->state(__('team.role'))->badge()->color('gray')->icon('heroicon-m-lifebuoy'),
                TextColumn::make('status')->label(__('team.fields.status'))->badge()->sortable()->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray')->formatStateUsing(fn (string $state): string => __("team.statuses.{$state}")),
                TextColumn::make('assigned_count')->label(__('team.columns.assigned'))->numeric()->sortable(),
                TextColumn::make('open_count')->label(__('team.columns.open'))->numeric()->sortable(),
                TextColumn::make('resolved_count')->label(__('team.columns.resolved'))->numeric()->sortable(),
                TextColumn::make('last_activity_at')->label(__('team.columns.last_activity'))->dateTime('d/m/Y H:i')->sortable()->placeholder(__('team.never')),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('team.fields.status'))->native()->placeholder(__('team.filters.all'))
                    ->options(collect(array_keys(self::STATUS_COLORS))->mapWithKeys(fn (string $status): array => [$status => __("team.statuses.{$status}")])->all())
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? null) === null || $data['value'] === ''
                        ? $query->where('platform_admins.status', '!=', PlatformAdmin::STATUS_REMOVED)
                        : $query->where('platform_admins.status', $data['value'])),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->recordActions([
                Action::make('view')->label(__('team.actions.view'))->icon('heroicon-m-eye')->url(fn (Model $record): string => static::getUrl('view', ['record' => $record->getKey()])),
                ActionGroup::make([
                    SupportTeamActions::edit(), SupportTeamActions::resendInvitation(), SupportTeamActions::resetPassword(),
                    SupportTeamActions::suspend(), SupportTeamActions::reactivate(), SupportTeamActions::remove(),
                ])->label(__('team.actions.more')),
            ])
            ->recordUrl(fn (Model $record): string => static::getUrl('view', ['record' => $record->getKey()]))
            ->emptyStateHeading(__('team.empty.heading'))->emptyStateDescription(__('team.empty.description'))->emptyStateIcon('heroicon-o-user-group');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'md' => 2])->components([
            Section::make(__('team.profile'))->columns(2)->schema([
                TextEntry::make('name')->label(__('team.fields.name'))->placeholder('—'),
                TextEntry::make('email')->label(__('team.fields.email'))->copyable(),
                TextEntry::make('phone')->label(__('team.fields.phone'))->placeholder('—'),
                TextEntry::make('role')->label(__('team.fields.role'))->state(__('team.role'))->badge()->color('gray'),
                TextEntry::make('status')->label(__('team.fields.status'))->badge()->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray')->formatStateUsing(fn (string $state): string => __("team.statuses.{$state}")),
                TextEntry::make('last_activity_at')->label(__('team.columns.last_activity'))->dateTime('d/m/Y H:i')->placeholder(__('team.never')),
                TextEntry::make('invited_at')->label(__('team.fields.invited_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('internal_notes')->label(__('team.fields.notes'))->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('team.workload'))->columns(3)->schema([
                TextEntry::make('assigned_count')->label(__('team.columns.assigned'))->numeric(),
                TextEntry::make('open_count')->label(__('team.columns.open'))->numeric(),
                TextEntry::make('resolved_count')->label(__('team.columns.resolved'))->numeric(),
            ]),
            Section::make(__('team.access'))->columns(1)->schema([
                TextEntry::make('permissions')->label(__('team.fields.permissions'))->state(fn (PlatformAdmin $record): array => collect(SupportPermissions::DEFAULTS)->map(fn (bool $default, string $permission): string => __("team.permissions.{$permission}").' : '.(SupportPermissions::can($record, $permission) ? __('team.allowed') : __('team.denied')))->values()->all())->listWithLineBreaks(),
                TextEntry::make('scope')->label(__('team.fields.scope'))->state(function (PlatformAdmin $record): array {
                    $ids = app(SupportTeam::class)->scopeOf($record);

                    return $ids === [] ? [__('team.all_clients')] : DB::connection('pgsql_platform')->table('organizations')->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
                })->listWithLineBreaks(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportAgents::route('/'),
            'view' => ViewSupportAgent::route('/{record}'),
        ];
    }
}
