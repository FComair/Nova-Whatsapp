<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages\CreateAdminProfile;
use App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages\EditAdminProfile;
use App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages\ListAdminProfiles;
use App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages\ViewAdminProfile;
use App\Modules\Admin\Filament\Support\AdminProfileActions;
use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\AdminTeam;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\AdminPermissions;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Administrateurs et accès (docs/38) : liste, création par invitation, profil détaillé, modification du rôle et des permissions, suspension,
 * réactivation, retrait, réinitialisation du mot de passe, déconnexion forcée. Réservé au Super Admin (`admins.*` n'est donnée à aucun autre
 * rôle). Ne concerne pas les agents de support ni les éditeurs de contenu, qui ont leur écran (« Équipe de support »).
 */
class AdminProfileResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = PlatformAdmin::class;

    protected static ?string $viewPermission = 'admins.view';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'admins';

    public const STATUS_COLORS = [
        PlatformAdmin::STATUS_ACTIVE => 'success',
        PlatformAdmin::STATUS_INVITED => 'info',
        PlatformAdmin::STATUS_SUSPENDED => 'warning',
        PlatformAdmin::STATUS_REMOVED => 'danger',
    ];

    public const ROLE_COLORS = [
        AdminPermissions::SUPER_ADMIN => 'primary',
        AdminPermissions::OPERATIONS => 'info',
        AdminPermissions::SUPPORT => 'success',
        AdminPermissions::READ_ONLY => 'gray',
        AdminPermissions::CUSTOM => 'warning',
    ];

    public static function getModelLabel(): string
    {
        return __('admins.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admins.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admins.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.configuration');
    }

    public static function canCreate(): bool
    {
        return AdminAccess::can('admins.create');
    }

    public static function canEdit(Model $record): bool
    {
        return AdminAccess::can('admins.edit');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * Les administrateurs (hors agents et éditeurs) avec qui les a ajoutés.
     *
     * @return Builder<PlatformAdmin>
     */
    public static function getEloquentQuery(): Builder
    {
        return PlatformAdmin::query()
            ->whereNull('platform_admins.support_role')
            ->select('platform_admins.*')
            ->selectRaw('(select coalesce(c.name, c.email) from platform_admins c where c.id = platform_admins.created_by) as creator_name')
            ->selectRaw("coalesce(platform_admins.admin_role, 'super_admin') as role_key");
    }

    public static function form(Schema $schema): Schema
    {
        $roles = collect(AdminPermissions::ROLES);

        return $schema->columns(1)->components([
            Section::make(__('admins.profile'))->columns(['default' => 1, 'md' => 2])->schema([
                TextInput::make('name')->label(__('admins.fields.name'))->required()->maxLength(120)->autocomplete('off'),
                TextInput::make('email')->label(__('admins.fields.email'))->email()->required()->maxLength(190)->autocomplete('off')
                    ->disabled(fn (?PlatformAdmin $record): bool => $record !== null)->dehydrated(fn (?PlatformAdmin $record): bool => $record === null)
                    ->helperText(__('admins.fields.email_help')),
                TextInput::make('phone')->label(__('admins.fields.phone'))->tel()->maxLength(30),
                Textarea::make('internal_notes')->label(__('admins.fields.notes'))->helperText(__('admins.fields.notes_help'))->rows(3)->maxLength(2000)->columnSpanFull(),
            ]),
            Section::make(__('admins.fields.role'))->description(__('admins.fields.role_help'))->schema([
                Radio::make('role')->hiddenLabel()->required()->live()->default(AdminPermissions::SUPPORT)
                    ->options($roles->mapWithKeys(fn (string $role): array => [$role => __("admins.roles.{$role}.name")])->all())
                    ->descriptions($roles->mapWithKeys(fn (string $role): array => [$role => __("admins.roles.{$role}.description")])->all()),
            ]),
            Section::make(__('admins.fields.permissions'))->visible(fn (Get $get): bool => $get('role') !== AdminPermissions::CUSTOM)->schema([
                TextEntry::make('preset')->hiddenLabel()->state(fn (Get $get): array => self::summary((string) $get('role')))->listWithLineBreaks()->bulleted(),
            ]),
            Section::make(__('admins.fields.permissions'))->description(__('admins.fields.permissions_help'))->visible(fn (Get $get): bool => $get('role') === AdminPermissions::CUSTOM)
                ->schema(collect(AdminPermissions::MODULES)->map(fn (array $actions, string $module) => Section::make((string) __("admins.modules.{$module}"))->compact()->schema([
                    CheckboxList::make("perm_{$module}")->hiddenLabel()->bulkToggleable()->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                        ->options(collect($actions)->mapWithKeys(fn (string $action): array => [$action => __("admins.permissions.{$module}.{$action}")])->all()),
                ]))->values()->all()),
        ]);
    }

    /**
     * Valeurs du formulaire d'un compte : rôle et, pour `custom`, cases cochées par module.
     *
     * @return array<string, mixed>
     */
    public static function formState(PlatformAdmin $admin): array
    {
        $state = ['name' => $admin->name, 'email' => $admin->email, 'phone' => $admin->phone, 'internal_notes' => $admin->internal_notes, 'role' => $admin->adminRole()];
        $granted = AdminPermissions::forRole($admin->adminRole(), $admin->permissions);

        foreach (AdminPermissions::MODULES as $module => $actions) {
            $state["perm_{$module}"] = array_values(array_filter($actions, fn (string $action): bool => in_array("{$module}.{$action}", $granted, true)));
        }

        return $state;
    }

    /**
     * Liste `module.action` des cases cochées du formulaire.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function chosenPermissions(array $data): array
    {
        $chosen = [];

        foreach (AdminPermissions::MODULES as $module => $actions) {
            foreach ((array) ($data["perm_{$module}"] ?? []) as $action) {
                if (in_array($action, $actions, true)) {
                    $chosen[] = "{$module}.{$action}";
                }
            }
        }

        return $chosen;
    }

    /**
     * Ce que donne un rôle prédéfini, module par module, pour l'aperçu du formulaire.
     *
     * @return list<string>
     */
    public static function summary(string $role): array
    {
        if (! AdminPermissions::isRole($role) || $role === AdminPermissions::CUSTOM) {
            return [];
        }

        return self::lines(AdminPermissions::forRole($role));
    }

    /**
     * Permissions regroupées par module, une ligne par module : « Clients : Voir, Modifier ».
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    private static function lines(array $granted): array
    {
        $lines = [];

        foreach ([...array_keys(AdminPermissions::MODULES), 'admins'] as $module) {
            $actions = array_values(array_filter($granted, fn (string $permission): bool => str_starts_with($permission, "{$module}.")));

            if ($actions !== []) {
                $lines[] = __("admins.modules.{$module}").' : '.collect($actions)->map(fn (string $permission): string => (string) __('admins.permissions.'.$permission))->implode(', ');
            }
        }

        return $lines;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->paginated([10, 25, 50])->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('name')->label(__('admins.fields.name'))->searchable()->sortable()->weight('medium')->placeholder('—'),
                TextColumn::make('email')->label(__('admins.fields.email'))->searchable()->sortable()->copyable()->formatStateUsing(fn (?string $state): ?string => $state),
                TextColumn::make('role_key')->label(__('admins.fields.role'))->badge()->sortable()->color(fn (string $state): string => self::ROLE_COLORS[$state] ?? 'gray')->formatStateUsing(fn (string $state): string => __("admins.roles.{$state}.name")),
                TextColumn::make('status')->label(__('admins.fields.status'))->badge()->sortable()->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray')->formatStateUsing(fn (string $state): string => __("admins.statuses.{$state}")),
                TextColumn::make('last_login_at')->label(__('admins.fields.last_login'))->dateTime('d/m/Y H:i')->sortable()->placeholder(__('admins.never')),
                TextColumn::make('created_at')->label(__('admins.fields.created_at'))->date('d/m/Y')->sortable(),
                TextColumn::make('creator_name')->label(__('admins.fields.created_by'))->placeholder('—'),
                TextColumn::make('permissions_count')->label(__('admins.fields.permissions_count'))->state(fn (PlatformAdmin $record): int => count(AdminPermissions::forRole($record->adminRole(), $record->permissions)))->numeric(),
            ])
            ->filters([
                SelectFilter::make('role')->label(__('admins.fields.role'))->native()->placeholder(__('admins.filters.all'))
                    ->options(collect(AdminPermissions::ROLES)->mapWithKeys(fn (string $role): array => [$role => __("admins.roles.{$role}.name")])->all())
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? '') === '' ? $query : $query->whereRaw("coalesce(platform_admins.admin_role, 'super_admin') = ?", [$data['value']])),
                SelectFilter::make('status')->label(__('admins.fields.status'))->native()->placeholder(__('admins.filters.all'))
                    ->options(collect(array_keys(self::STATUS_COLORS))->mapWithKeys(fn (string $status): array => [$status => __("admins.statuses.{$status}")])->all())
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? '') === ''
                        ? $query->where('platform_admins.status', '!=', PlatformAdmin::STATUS_REMOVED)
                        : $query->where('platform_admins.status', $data['value'])),
                self::dateRange('created', 'admins.filters.created', 'created_at'),
                self::dateRange('login', 'admins.filters.last_login', 'last_login_at'),
            ], layout: FiltersLayout::AboveContent)
            ->deferFilters(false)
            ->recordActions([
                Action::make('view')->label(__('admins.actions.view'))->icon('heroicon-m-eye')->url(fn (Model $record): string => static::getUrl('view', ['record' => $record->getKey()])),
                ActionGroup::make([
                    AdminProfileActions::edit(), AdminProfileActions::resendInvitation(), AdminProfileActions::resetPassword(), AdminProfileActions::forceLogout(),
                    AdminProfileActions::suspend(), AdminProfileActions::reactivate(), AdminProfileActions::remove(),
                ])->label(__('admins.actions.more')),
            ])
            ->recordUrl(fn (Model $record): string => static::getUrl('view', ['record' => $record->getKey()]))
            ->emptyStateHeading(__('admins.empty.heading'))->emptyStateDescription(__('admins.empty.description'))->emptyStateIcon('heroicon-o-shield-check');
    }

    /** Filtre « de … à … » sur une colonne de date. */
    private static function dateRange(string $name, string $label, string $column): Filter
    {
        return Filter::make($name)->label(__($label))
            ->schema([
                DatePicker::make("{$name}_from")->label(__($label).' · '.__('admins.filters.from'))->native(false)->displayFormat('d/m/Y'),
                DatePicker::make("{$name}_until")->label(__($label).' · '.__('admins.filters.until'))->native(false)->displayFormat('d/m/Y'),
            ])->columns(2)
            ->query(function (Builder $query, array $data) use ($name, $column): Builder {
                $from = $data["{$name}_from"] ?? null;
                $until = $data["{$name}_until"] ?? null;

                return $query
                    ->when($from, fn (Builder $q) => $q->where("platform_admins.{$column}", '>=', CarbonImmutable::parse((string) $from)->startOfDay()))
                    ->when($until, fn (Builder $q) => $q->where("platform_admins.{$column}", '<=', CarbonImmutable::parse((string) $until)->endOfDay()));
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(['default' => 1, 'md' => 2])->components([
            Section::make(__('admins.profile'))->columns(2)->schema([
                TextEntry::make('name')->label(__('admins.fields.name'))->placeholder('—'),
                TextEntry::make('email')->label(__('admins.fields.email'))->copyable()->formatStateUsing(fn (?string $state): ?string => $state),
                TextEntry::make('phone')->label(__('admins.fields.phone'))->placeholder('—'),
                TextEntry::make('role_key')->label(__('admins.fields.role'))->badge()->color(fn (string $state): string => self::ROLE_COLORS[$state] ?? 'gray')->formatStateUsing(fn (string $state): string => __("admins.roles.{$state}.name")),
                TextEntry::make('status')->label(__('admins.fields.status'))->badge()->color(fn (string $state): string => self::STATUS_COLORS[$state] ?? 'gray')->formatStateUsing(fn (string $state): string => __("admins.statuses.{$state}")),
                TextEntry::make('created_at')->label(__('admins.fields.created_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('creator_name')->label(__('admins.fields.created_by'))->placeholder('—'),
                TextEntry::make('last_login_at')->label(__('admins.fields.last_login'))->dateTime('d/m/Y H:i')->placeholder(__('admins.never')),
                TextEntry::make('last_login_ip')->label(__('admins.fields.last_login_ip'))->placeholder('—'),
                TextEntry::make('invited_at')->label(__('admins.fields.invited_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                TextEntry::make('internal_notes')->label(__('admins.fields.notes'))->placeholder('—')->columnSpanFull(),
            ]),
            Section::make(__('admins.work'))->columns(3)->schema([
                TextEntry::make('tickets_handled')->label(__('admins.work_tickets'))->state(fn (PlatformAdmin $record): int => app(AdminTeam::class)->activity($record)['tickets_handled'])->numeric(),
                TextEntry::make('clients_managed')->label(__('admins.work_clients'))->state(fn (PlatformAdmin $record): int => app(AdminTeam::class)->activity($record)['clients_managed'])->numeric(),
                TextEntry::make('actions_count')->label(__('admins.work_actions'))->state(fn (PlatformAdmin $record): int => app(AdminTeam::class)->activity($record)['actions'])->numeric(),
            ]),
            Section::make(__('admins.fields.permissions'))->columnSpanFull()->schema([
                TextEntry::make('permissions_list')->hiddenLabel()->state(fn (PlatformAdmin $record): array => self::grantedLines($record))->listWithLineBreaks()->bulleted(),
            ]),
            Section::make(__('admins.logins'))->schema([
                TextEntry::make('logins_list')->hiddenLabel()->placeholder(__('admins.none'))->state(fn (PlatformAdmin $record): array => collect(app(AdminTeam::class)->activity($record)['logins'])
                    ->map(fn (object $row): string => CarbonImmutable::parse((string) $row->created_at)->format('d/m/Y H:i').' · '.__('admins.login_events.'.($row->action === 'platform.login' ? 'ok' : 'failed')).' · '.($row->ip ?? '—'))->all())->listWithLineBreaks(),
            ]),
            Section::make(__('admins.activity'))->schema([
                TextEntry::make('activity_list')->hiddenLabel()->placeholder(__('admins.none'))->state(fn (PlatformAdmin $record): array => collect(app(AdminTeam::class)->activity($record)['activity'])
                    ->map(fn (object $row): string => CarbonImmutable::parse((string) $row->created_at)->format('d/m/Y H:i').' · '.$row->action.($row->entity_type !== null ? ' ('.$row->entity_type.')' : ''))->all())->listWithLineBreaks(),
            ]),
        ]);
    }

    /**
     * Permissions accordées au compte, module par module, avec le libellé de chacune.
     *
     * @return list<string>
     */
    private static function grantedLines(PlatformAdmin $admin): array
    {
        return self::lines(AdminPermissions::forRole($admin->adminRole(), $admin->permissions)) ?: [__('admins.none')];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdminProfiles::route('/'),
            'create' => CreateAdminProfile::route('/create'),
            'view' => ViewAdminProfile::route('/{record}'),
            'edit' => EditAdminProfile::route('/{record}/edit'),
        ];
    }
}
