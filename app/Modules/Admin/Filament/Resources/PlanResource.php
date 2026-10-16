<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\PlanResource\Pages\CreatePlan;
use App\Modules\Admin\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Modules\Admin\Filament\Resources\PlanResource\Pages\ListPlans;
use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Admin\Models\PlatformPlan;
use App\Modules\Admin\Models\PlatformSector;
use App\Modules\Admin\Services\PlanManager;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Services\PlanCatalog;
use App\Support\Money;
use BackedEnum;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Formules d'abonnement (Super Admin, docs/40) : liste filtrable avec abonnés, pages de création et de modification avec aperçu en direct de
 * la carte telle que les clients la verront, publication, duplication, archivage, suppression et migration des abonnés.
 *
 * Toutes les écritures passent par PlanManager : permission, transaction, audit, et surtout la protection des abonnés en cours
 * (nouvelle version quand un changement commercial touche une formule qui a des abonnés).
 */
class PlanResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = PlatformPlan::class;

    /** Permission nécessaire pour voir cet écran (docs/38). */
    protected static ?string $viewPermission = 'settings.view';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('plans.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('plans.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('plans.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.configuration');
    }

    public static function canCreate(): bool
    {
        return AdminAccess::can('settings.edit');
    }

    public static function canEdit(Model $record): bool
    {
        return AdminAccess::can('settings.edit') && $record instanceof Plan && $record->superseded_by === null;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return array<string, string> */
    private static function options(string $group, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => __("plans.{$group}.{$key}")])->all();
    }

    public static function form(Schema $schema): Schema
    {
        $locales = (array) config('nova.locales');

        return $schema->columns(['default' => 1, 'xl' => 3])->components([
            Group::make([
                Section::make(__('plans.form.identity'))->columns(2)->schema([
                    TextInput::make('name')->label(__('plans.form.name'))->helperText(__('plans.form.name_help'))->required()->maxLength(60)->autocomplete(false)->live(onBlur: true)->columnSpanFull(),
                    Select::make('status')->label(__('plans.form.status'))->helperText(__('plans.form.status_help'))->options(self::options('statuses', Plan::STATUSES))->default(Plan::DRAFT)->required()->live(),
                    TextInput::make('position')->label(__('plans.form.position'))->numeric()->minValue(0),
                    Textarea::make('internal_description')->label(__('plans.form.internal_description'))->helperText(__('plans.form.internal_description_help'))->rows(2)->columnSpanFull(),
                    ...array_map(fn (string $language): Textarea => Textarea::make('public_description.'.$language)
                        ->label(__('plans.form.public_description').' ('.strtoupper($language).')')
                        ->helperText($language === config('app.fallback_locale') ? __('plans.form.public_description_help') : null)
                        ->rows(2)->maxLength(300)->live(onBlur: true)->columnSpanFull(), $locales),
                ]),
                Section::make(__('plans.form.pricing'))->columns(2)->schema([
                    Select::make('plan_type')->label(__('plans.form.type'))->helperText(__('plans.form.type_help'))->options(self::options('types', Plan::TYPES))->default('monthly')->required()->live()->columnSpanFull(),
                    Select::make('billing_frequency')->label(__('plans.form.frequency'))->options(self::options('frequencies', ['monthly', 'quarterly', 'yearly', 'custom']))->default('monthly')->required()->live(),
                    TextInput::make('billing_interval_months')->label(__('plans.form.interval'))->numeric()->minValue(1)->maxValue(60)->default(1)->live(onBlur: true)
                        ->visible(fn (Get $get): bool => $get('billing_frequency') === 'custom'),
                    self::moneyInput('price_cents', 'plans.form.price', 'plans.form.price_help')->required(fn (Get $get): bool => in_array($get('plan_type'), Plan::BILLABLE_TYPES, true)),
                    Select::make('currency')->label(__('plans.form.currency'))->options(array_combine(PlanManager::CURRENCIES, PlanManager::CURRENCIES))->default('EUR')->required()->live(),
                    Select::make('tax_mode')->label(__('plans.form.tax_mode'))->helperText(__('plans.form.tax_mode_help'))->options(self::options('tax_modes', ['excluded', 'included']))->default('excluded')->required()->live(),
                    self::moneyInput('setup_fee_cents', 'plans.form.setup_fee', 'plans.form.setup_fee_help'),
                    TextInput::make('trial_days')->label(__('plans.form.trial_days'))->helperText(__('plans.form.trial_days_help'))->numeric()->minValue(0)->maxValue(365)->live(onBlur: true),
                ]),
                Section::make(__('plans.form.limits'))->description(__('plans.form.limits_help'))->columns(2)->schema(
                    array_map(fn (string $key): TextInput => TextInput::make('limits.'.$key)->label(__("plans.limit_labels.{$key}"))->numeric()->minValue(0)->live(onBlur: true), Plan::LIMIT_KEYS),
                ),
                Section::make(__('plans.form.features'))->description(__('plans.form.features_help'))->schema([
                    Repeater::make('features')->hiddenLabel()->reorderable()->collapsible()->collapsed()->cloneable()->defaultItems(0)->addActionLabel(__('plans.form.add_feature'))->live()
                        ->itemLabel(fn (array $state): ?string => $state['label'][config('app.fallback_locale')] ?? null)
                        ->columns(2)
                        ->schema([
                            TextInput::make('label.fr')->label(__('plans.form.feature_label_fr'))->required()->maxLength(300)->live(onBlur: true),
                            Select::make('state')->label(__('plans.form.feature_state'))->options(self::options('feature_states', Plan::FEATURE_STATES))->default('included')->required()->live(),
                            TextInput::make('label.nl')->label(__('plans.form.feature_label_nl'))->maxLength(300)->live(onBlur: true),
                            TextInput::make('label.en')->label(__('plans.form.feature_label_en'))->maxLength(300)->live(onBlur: true),
                            TextInput::make('value')->label(__('plans.form.feature_value'))->maxLength(80)->live(onBlur: true)->columnSpanFull(),
                        ]),
                ]),
                Section::make(__('plans.form.visibility_section'))->columns(2)->schema([
                    Select::make('visibility')->label(__('plans.form.visibility'))->helperText(__('plans.form.visibility_help'))->options(self::options('visibilities', Plan::VISIBILITIES))->default('public')->required()->live()->columnSpanFull(),
                    TextInput::make('invite_code')->label(__('plans.form.invite_code'))->helperText(__('plans.form.invite_code_help'))->maxLength(40)
                        ->visible(fn (Get $get): bool => $get('visibility') === 'invite_only'),
                    Select::make('segments.sectors')->label(__('plans.form.segment_sectors'))->multiple()->searchable()->options(fn (): array => self::sectorOptions())
                        ->visible(fn (Get $get): bool => $get('visibility') === 'segments'),
                    Select::make('segments.countries')->label(__('plans.form.segment_countries'))->multiple()->searchable()->options(fn (): array => self::countryOptions())
                        ->visible(fn (Get $get): bool => $get('visibility') === 'segments'),
                    DateTimePicker::make('available_from')->label(__('plans.form.available_from'))->seconds(false)->native(false)->timezone('UTC')->live(),
                    DateTimePicker::make('available_until')->label(__('plans.form.available_until'))->helperText(__('plans.form.availability_help'))->seconds(false)->native(false)->timezone('UTC')->live()->after('available_from'),
                    Toggle::make('is_recommended')->label(__('plans.form.recommended'))->helperText(__('plans.form.recommended_help'))->live(),
                    Select::make('cta_type')->label(__('plans.form.cta'))->options(self::options('cta_types', Plan::CTA_TYPES))->default('choose_plan')->required()->live(),
                    TextInput::make('cta_url')->label(__('plans.form.cta_url'))->helperText(__('plans.form.cta_url_help'))->maxLength(255)->columnSpanFull(),
                ]),
            ])->columnSpan(['default' => 1, 'xl' => 2]),

            Group::make([
                Section::make(__('plans.form.preview'))->description(__('plans.preview.caption'))->schema([
                    View::make('admin.plans.preview')->viewData(fn (Get $get): array => ['preview' => self::preview($get)]),
                ]),
            ])->columnSpan(['default' => 1, 'xl' => 1]),
        ]);
    }

    /** @return array<string, string> code => nom du secteur */
    private static function sectorOptions(): array
    {
        return PlatformSector::query()->orderBy('name')->get()->mapWithKeys(fn (PlatformSector $sector): array => [$sector->code => $sector->label(app()->getLocale())])->all();
    }

    /** @return array<string, string> code pays => nom */
    private static function countryOptions(): array
    {
        return collect((array) config('nova.countries'))->mapWithKeys(fn (string $country): array => [$country => (string) __('ui.onboarding.company.countries.'.$country)])->all();
    }

    /** Champ en euros à l'écran, en centimes en base. */
    private static function moneyInput(string $name, string $label, string $help): TextInput
    {
        return TextInput::make($name)->label(__($label))->helperText(__($help))->inputMode('decimal')->suffix('€')->live(onBlur: true)->default(0)
            ->formatStateUsing(fn ($state): string => $state === null || $state === '' ? '' : number_format(((int) $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn ($state): int => Money::parseToCents((string) $state) ?? 0)
            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && Money::parseToCents((string) $value) === null) {
                    $fail(__('plans.errors.price'));
                }
            }]);
    }

    /**
     * Ce que le client verra, calculé avec le MÊME code que la page publique (PlanCatalog::present) à partir de ce qui est saisi.
     *
     * @return array<string, mixed>
     */
    public static function preview(Get $get): array
    {
        $cents = fn (string $key): int => Money::parseToCents((string) ($get($key) ?? '0')) ?? 0;
        $frequency = (string) ($get('billing_frequency') ?? 'monthly');
        $interval = $frequency === 'custom' ? max(1, (int) ($get('billing_interval_months') ?? 1)) : (Plan::FREQUENCIES[$frequency] ?? 1);

        $plan = new Plan([
            'name' => (string) ($get('name') ?? ''),
            'code' => 'preview',
            'status' => (string) ($get('status') ?? Plan::DRAFT),
            'plan_type' => (string) ($get('plan_type') ?? 'monthly'),
            'billing_frequency' => $frequency,
            'billing_interval_months' => $interval,
            'price_cents' => $cents('price_cents'),
            'setup_fee_cents' => $cents('setup_fee_cents'),
            'currency' => (string) ($get('currency') ?? 'EUR'),
            'tax_mode' => (string) ($get('tax_mode') ?? 'excluded'),
            'trial_days' => filled($get('trial_days')) ? (int) $get('trial_days') : null,
            'limits' => array_map(fn ($value): ?int => filled($value) ? (int) $value : null, (array) ($get('limits') ?? [])),
            'features' => array_values((array) ($get('features') ?? [])),
            'public_description' => (array) ($get('public_description') ?? []),
            'visibility' => (string) ($get('visibility') ?? 'public'),
            'is_recommended' => (bool) $get('is_recommended'),
            'cta_type' => (string) ($get('cta_type') ?? 'choose_plan'),
            'cta_url' => $get('cta_url'),
            'available_from' => filled($get('available_from')) ? $get('available_from') : null,
            'available_until' => filled($get('available_until')) ? $get('available_until') : null,
        ]);

        $locale = app()->getLocale();
        $card = app(PlanCatalog::class)->present($plan, $locale);
        $period = in_array($interval, [1, 3, 12], true) ? __("plans.periods.{$interval}") : __('plans.periods.custom', ['count' => $interval]);

        return [
            'card' => $card,
            'price' => $plan->plan_type === 'custom' ? __('plans.on_quote') : ($plan->price_cents === 0 ? __('plans.free') : Money::format($plan->price_cents, trim($plan->currency), $locale)),
            'period' => $plan->price_cents > 0 && $plan->plan_type !== 'custom' ? $period : null,
            'setup_fee' => $plan->setup_fee_cents > 0 ? Money::format($plan->setup_fee_cents, trim($plan->currency), $locale) : null,
            'tax' => $plan->price_cents > 0 ? __($plan->tax_mode === 'included' ? 'plans.preview.tax_included' : 'plans.preview.tax_excluded') : null,
            'listed' => self::whyNotListed($plan),
            'cta_label' => __("plans.cta_types.{$plan->cta_type}"),
        ];
    }

    /** Raison pour laquelle la formule n'apparaît pas sur la page publique, ou null si elle y est. */
    private static function whyNotListed(Plan $plan): ?string
    {
        return match (true) {
            $plan->status === Plan::DRAFT => 'why_draft',
            $plan->status === Plan::INACTIVE => 'why_inactive',
            $plan->status === Plan::ARCHIVED => 'why_archived',
            $plan->visibility !== 'public' => 'why_visibility',
            ! $plan->inAvailabilityWindow() => 'why_window',
            default => null,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position', fn (): bool => AdminAccess::can('settings.edit'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->select('plans.*')->selectSub(
                DB::connection(PlatformAudit::CONNECTION)->table('subscriptions')->whereColumn('subscriptions.plan_id', 'plans.id')->where('subscriptions.status', '<>', 'canceled')->selectRaw('count(*)'),
                'subscribers_count',
            ))
            ->columns([
                TextColumn::make('name')->label(__('plans.columns.name'))
                    ->description(fn (PlatformPlan $record): string => $record->code.($record->version > 1 ? ' · '.__('plans.version', ['version' => $record->version]) : ''))
                    ->searchable(['name', 'code', 'internal_description'])->sortable()->weight('semibold'),
                TextColumn::make('status')->label(__('plans.columns.status'))->badge()
                    ->state(fn (PlatformPlan $record): string => $record->superseded_by !== null ? 'superseded' : $record->status)
                    ->formatStateUsing(fn (string $state): string => $state === 'superseded' ? __('plans.statuses.archived').' · '.__('plans.version', ['version' => '↑']) : __("plans.statuses.{$state}"))
                    ->color(fn (string $state): string => ['draft' => 'gray', 'active' => 'success', 'inactive' => 'warning', 'archived' => 'gray', 'superseded' => 'gray'][$state] ?? 'gray')
                    ->icon(fn (string $state): string => ['draft' => 'heroicon-m-pencil', 'active' => 'heroicon-m-check-circle', 'inactive' => 'heroicon-m-pause-circle', 'archived' => 'heroicon-m-archive-box', 'superseded' => 'heroicon-m-archive-box'][$state] ?? 'heroicon-m-minus-circle')
                    ->sortable(),
                TextColumn::make('plan_type')->label(__('plans.columns.type'))->formatStateUsing(fn (string $state): string => __("plans.types.{$state}"))->toggleable(),
                TextColumn::make('price_cents')->label(__('plans.columns.price'))->sortable()
                    ->state(fn (PlatformPlan $record): string => $record->plan_type === 'custom' ? __('plans.on_quote') : ($record->price_cents === 0 ? __('plans.free') : Money::format($record->price_cents, trim($record->currency), app()->getLocale())))
                    ->description(fn (PlatformPlan $record): ?string => $record->price_cents > 0 ? __($record->tax_mode === 'included' ? 'plans.preview.tax_included' : 'plans.preview.tax_excluded') : null),
                TextColumn::make('billing_interval_months')->label(__('plans.columns.cycle'))->toggleable()
                    ->formatStateUsing(fn ($state): string => in_array((int) $state, [1, 3, 12], true) ? __("plans.periods.{$state}") : __('plans.periods.custom', ['count' => $state])),
                TextColumn::make('visibility')->label(__('plans.columns.visibility'))->badge()->color('gray')->formatStateUsing(fn (string $state): string => __("plans.visibilities.{$state}")),
                TextColumn::make('subscribers_count')->label(__('plans.columns.subscribers'))->numeric()->alignEnd()->sortable(),
                IconColumn::make('is_recommended')->label(__('plans.columns.recommended'))->boolean()->trueIcon('heroicon-s-star')->falseIcon('heroicon-o-minus')->falseColor('gray')->toggleable(),
                TextColumn::make('available_from')->label(__('plans.columns.availability'))->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (PlatformPlan $record): ?string => $record->available_from === null && $record->available_until === null ? null : ($record->available_from?->format('d/m/Y') ?? '…').' → '.($record->available_until?->format('d/m/Y') ?? '…')),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('plans.filters.status'))->options(self::options('statuses', Plan::STATUSES))->multiple(),
                SelectFilter::make('plan_type')->label(__('plans.filters.type'))->options(self::options('types', Plan::TYPES))->multiple(),
                SelectFilter::make('visibility')->label(__('plans.filters.visibility'))->options(self::options('visibilities', Plan::VISIBILITIES))->multiple(),
                Filter::make('with_subscribers')->label(__('plans.filters.with_subscribers'))->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('subscriptions')->whereColumn('subscriptions.plan_id', 'plans.id')->where('subscriptions.status', '<>', 'canceled'))),
            ])
            ->recordUrl(fn (PlatformPlan $record): ?string => static::canEdit($record) ? static::getUrl('edit', ['record' => $record]) : null)
            ->recordActions([
                Action::make('edit')->label(__('plans.actions.edit'))->icon('heroicon-o-pencil-square')
                    ->visible(fn (PlatformPlan $record): bool => static::canEdit($record))
                    ->url(fn (PlatformPlan $record): string => static::getUrl('edit', ['record' => $record])),
                ActionGroup::make([
                    Action::make('publish')->label(__('plans.actions.publish'))->icon('heroicon-o-rocket-launch')->color('success')
                        ->visible(fn (PlatformPlan $record): bool => self::mayEdit($record) && in_array($record->status, [Plan::DRAFT, Plan::INACTIVE], true))
                        ->requiresConfirmation()->modalHeading(__('plans.confirm.publish_title'))->modalDescription(__('plans.confirm.publish_body'))
                        ->action(fn (PlatformPlan $record) => self::attempt(fn () => app(PlanManager::class)->setStatus($record, Plan::ACTIVE, self::adminId()), 'plans.saved.published')),
                    Action::make('unpublish')->label(__('plans.actions.unpublish'))->icon('heroicon-o-pause-circle')
                        ->visible(fn (PlatformPlan $record): bool => self::mayEdit($record) && $record->status === Plan::ACTIVE)
                        ->requiresConfirmation()->modalHeading(__('plans.confirm.unpublish_title'))
                        ->modalDescription(fn (PlatformPlan $record): string => __('plans.confirm.unpublish_body', ['count' => app(PlanManager::class)->subscribers($record)]))
                        ->action(fn (PlatformPlan $record) => self::attempt(fn () => app(PlanManager::class)->setStatus($record, Plan::INACTIVE, self::adminId()), 'plans.saved.unpublished')),
                    Action::make('duplicate')->label(__('plans.actions.duplicate'))->icon('heroicon-o-document-duplicate')
                        ->visible(fn (): bool => AdminAccess::can('settings.edit'))
                        ->action(fn (PlatformPlan $record) => self::attempt(fn () => app(PlanManager::class)->duplicate($record, self::adminId()), 'plans.saved.duplicated')),
                    Action::make('migrate')->label(__('plans.actions.migrate'))->icon('heroicon-o-arrow-right-circle')
                        ->visible(fn (PlatformPlan $record): bool => AdminAccess::can('settings.edit') && app(PlanManager::class)->subscribers($record) > 0)
                        ->modalHeading(fn (PlatformPlan $record): string => __('plans.confirm.migrate_title', ['name' => $record->name]))
                        ->modalDescription(fn (PlatformPlan $record): string => __('plans.confirm.migrate_body', ['count' => app(PlanManager::class)->subscribers($record)]))
                        ->schema(fn (PlatformPlan $record): array => [
                            Select::make('target')->label(__('plans.confirm.migrate_target'))->required()
                                ->options(fn (): array => PlatformPlan::query()->where('status', Plan::ACTIVE)->whereNull('superseded_by')->where('id', '<>', $record->id)->orderBy('position')->get()->filter(fn (Plan $plan): bool => $plan->purchasable())->pluck('name', 'id')->all()),
                        ])
                        ->action(function (array $data, PlatformPlan $record): void {
                            $target = PlatformPlan::query()->find($data['target']) ?? abort(404);
                            $count = 0;
                            self::attempt(function () use ($record, $target, &$count): void {
                                $count = app(PlanManager::class)->migrateSubscribers($record, $target, self::adminId());
                            }, 'plans.saved.migrated', function () use (&$count): array {
                                return ['count' => $count];
                            });
                        }),
                    Action::make('archive')->label(__('plans.actions.archive'))->icon('heroicon-o-archive-box')
                        ->visible(fn (PlatformPlan $record): bool => self::mayEdit($record) && $record->status !== Plan::ARCHIVED)
                        ->requiresConfirmation()
                        ->modalHeading(fn (PlatformPlan $record): string => __('plans.confirm.archive_title', ['name' => $record->name]))
                        ->modalDescription(fn (PlatformPlan $record): string => __('plans.confirm.archive_body', ['count' => app(PlanManager::class)->subscribers($record)]))
                        ->action(fn (PlatformPlan $record) => self::attempt(fn () => app(PlanManager::class)->setStatus($record, Plan::ARCHIVED, self::adminId()), 'plans.saved.archived')),
                    Action::make('delete')->label(__('plans.actions.delete'))->icon('heroicon-o-trash')->color('danger')
                        ->visible(fn (): bool => AdminAccess::can('settings.edit'))
                        ->requiresConfirmation()
                        ->modalHeading(fn (PlatformPlan $record): string => __('plans.confirm.delete_title', ['name' => $record->name]))
                        ->modalDescription(__('plans.confirm.delete_body'))
                        ->action(fn (PlatformPlan $record) => self::attempt(fn () => app(PlanManager::class)->delete($record, self::adminId()), 'plans.saved.deleted')),
                ]),
            ]);
    }

    /** Permission de modifier ET formule en vigueur (une version remplacée ne se modifie plus). */
    private static function mayEdit(Plan $record): bool
    {
        return AdminAccess::can('settings.edit') && $record->superseded_by === null;
    }

    /**
     * Exécute une opération du gestionnaire et dit le résultat ; un refus métier (abonnés, état, prix manquant…) s'affiche tel quel.
     *
     * @param  (Closure(): array<string, mixed>)|null  $params  paramètres du message de succès, évalués après l'opération
     */
    public static function attempt(Closure $operation, string $successKey, ?Closure $params = null): void
    {
        try {
            $operation();
        } catch (DomainException|InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            return;
        }

        Notification::make()->success()->title(__($successKey, $params === null ? [] : $params()))->send();
    }

    public static function adminId(): string
    {
        return (string) (Filament::auth()->user() ?? abort(403))->getAuthIdentifier();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
