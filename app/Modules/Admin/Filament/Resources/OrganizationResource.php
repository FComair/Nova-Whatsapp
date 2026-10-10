<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ListOrganizations;
use App\Modules\Admin\Filament\Resources\OrganizationResource\Pages\ViewOrganization;
use App\Modules\Admin\Filament\Support\HiddenFromSupportAgents;
use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Models\PlatformSector;
use App\Modules\Tenancy\Models\Organization;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Liste des clients (organisations) pour le Super Admin.
 *
 * Niveaux 1 et 2 de docs/02-roles-et-permissions.md §3.2 : compte, statut, plan, usage et configuration.
 * Aucune donnée personnelle des clients finaux ni aucune note n'est exposée ici. Les actions sensibles
 * (suspension, blocage, suppression) arrivent à l'étape Super Admin, avec re-authentification et motif obligatoire.
 */
class OrganizationResource extends Resource
{
    use HiddenFromSupportAgents;

    protected static ?string $model = PlatformOrganization::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 1;

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

    /** Lecture seule à cette étape. */
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

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('deleted_at'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('admin.organizations.name'))->searchable()->sortable(),
                TextColumn::make('status')
                    ->label(__('admin.organizations.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.statuses.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        Organization::STATUS_ACTIVE => 'success',
                        Organization::STATUS_TRIAL => 'info',
                        Organization::STATUS_PAST_DUE, Organization::STATUS_PAUSED => 'warning',
                        Organization::STATUS_SUSPENDED, Organization::STATUS_PENDING_DELETION, Organization::STATUS_DELETED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('sector')->label(__('admin.organizations.sector'))->formatStateUsing(fn (?string $state): ?string => self::sectorLabel($state))->toggleable(),
                TextColumn::make('signup_plan')->label(__('admin.organizations.plan'))->toggleable(),
                TextColumn::make('country')->label(__('admin.organizations.country'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('trial_ends_at')->label(__('admin.organizations.trial_ends_at'))->date()->toggleable(),
                TextColumn::make('created_at')->label(__('admin.organizations.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.organizations.status'))
                    ->options(fn (): array => collect([
                        Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_SUSPENDED,
                        Organization::STATUS_PAUSED, Organization::STATUS_PENDING_DELETION,
                    ])->mapWithKeys(fn (string $status): array => [$status => __("admin.statuses.{$status}")])->all()),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('admin.organizations.empty'));
    }

    /** Nom du secteur dans la langue de l'interface (le code brut si le secteur n'existe plus). */
    private static function sectorLabel(?string $code): ?string
    {
        if ($code === null) {
            return null;
        }

        // Une seule lecture par requête (cache en mémoire), au lieu d'une par ligne du tableau.
        $locale = app()->getLocale();
        $labels = Cache::store('array')->rememberForever("sector-labels:{$locale}", fn (): array => PlatformSector::query()->get()->mapWithKeys(fn (PlatformSector $sector): array => [$sector->code => $sector->label($locale)])->all());

        return $labels[$code] ?? $code;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.organizations.account'))->columns(3)->schema([
                TextEntry::make('name')->label(__('admin.organizations.name')),
                TextEntry::make('status')
                    ->label(__('admin.organizations.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.statuses.{$state}")),
                TextEntry::make('sector')->label(__('admin.organizations.sector'))->formatStateUsing(fn (?string $state): ?string => self::sectorLabel($state)),
                TextEntry::make('signup_plan')->label(__('admin.organizations.plan')),
                TextEntry::make('country')->label(__('admin.organizations.country')),
                TextEntry::make('timezone')->label(__('admin.organizations.timezone')),
                TextEntry::make('locale')->label(__('admin.organizations.locale')),
                TextEntry::make('trial_ends_at')->label(__('admin.organizations.trial_ends_at'))->dateTime()->placeholder('—'),
                TextEntry::make('created_at')->label(__('admin.organizations.created_at'))->dateTime(),
            ]),
            Section::make(__('admin.organizations.lifecycle'))->columns(3)->schema([
                TextEntry::make('suspended_at')->label(__('admin.organizations.suspended_at'))->dateTime()->placeholder('—'),
                TextEntry::make('suspension_reason')->label(__('admin.organizations.suspension_reason'))->placeholder('—'),
                TextEntry::make('pause_until')->label(__('admin.organizations.pause_until'))->dateTime()->placeholder('—'),
                TextEntry::make('deletion_scheduled_for')->label(__('admin.organizations.deletion_scheduled_for'))->dateTime()->placeholder('—'),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
        ];
    }
}
