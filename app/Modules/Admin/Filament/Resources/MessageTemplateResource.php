<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\MessageTemplateResource\Pages\ListMessageTemplates;
use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Admin\Models\PlatformMessageTemplate;
use App\Modules\Admin\Models\PlatformSector;
use App\Modules\Admin\Services\MessageTemplateManager;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Whatsapp\Models\MessageTemplateEvent;
use App\Modules\Whatsapp\Support\MessageTemplateLibrary;
use App\Modules\Whatsapp\Support\MessageTemplateStatus;
use App\Modules\Whatsapp\Support\MessageTemplateType;
use App\Modules\Whatsapp\Support\MessageVariables;
use BackedEnum;
use Closure;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Messages WhatsApp (Super Admin), secteur par secteur (docs/39) : créer, modifier, dupliquer, supprimer, activer / désactiver, aperçu avec
 * des données d'exemple, langues disponibles, circuit d'approbation (brouillon → en attente → approuvé / refusé, archivage) et historique.
 *
 * Les écritures passent par MessageTemplateManager (permission, transaction, journal des modèles et journal d'audit), pas par les pages
 * de création / édition de Filament. Seul un modèle approuvé et activé peut servir à un vrai envoi.
 */
class MessageTemplateResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = PlatformMessageTemplate::class;

    /** Permission nécessaire pour voir cet écran (docs/38). */
    protected static ?string $viewPermission = 'settings.view';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string
    {
        return __('message_templates.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('message_templates.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('message_templates.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.configuration');
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

    /** @return array<string, string> code => nom du secteur */
    public static function sectorOptions(): array
    {
        $locale = app()->getLocale();

        return PlatformSector::query()->orderBy('name')->get()->mapWithKeys(fn (PlatformSector $sector): array => [$sector->code => $sector->label($locale)])->all();
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return collect(MessageTemplateType::ALL)->mapWithKeys(fn (string $type): array => [$type => __("message_templates.types.{$type}")])->all();
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return collect(MessageTemplateStatus::ALL)->mapWithKeys(fn (string $status): array => [$status => __("message_templates.statuses.{$status}")])->all();
    }

    /** @return array<string, string> */
    private static function languageOptions(): array
    {
        return collect((array) config('nova.locales'))->mapWithKeys(fn (string $language): array => [$language => __("message_templates.languages.{$language}")])->all();
    }

    /**
     * Formulaire de création et de modification. Le secteur ne change plus après la création : c'est ce qui isole les secteurs
     * (pour déplacer un modèle, on le duplique vers l'autre secteur).
     *
     * @return list<Section|Select|TextInput|Toggle|Textarea>
     */
    public static function formSchema(?PlatformMessageTemplate $template = null): array
    {
        $variables = collect(MessageVariables::NAMES)->map(fn (string $name): string => '{{'.$name.'}}')->implode('  ');
        $fields = [];

        foreach ((array) config('nova.locales') as $language) {
            $fields[] = Textarea::make('translations.'.$language)
                ->label(__('message_templates.form.body', ['language' => __("message_templates.languages.{$language}")]))
                ->rows(5)
                ->maxLength(MessageVariables::MAX_LENGTH)
                ->required($language === config('app.fallback_locale'))
                ->helperText($language === config('app.fallback_locale') ? __('message_templates.form.body_required') : __('message_templates.form.body_optional'))
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $manager = app(MessageTemplateManager::class);

                        foreach (MessageVariables::problems((string) $value) as $problem) {
                            $fail($manager->describe($problem));

                            return;
                        }
                    },
                ]);
        }

        return [
            Select::make('sector_code')
                ->label(__('message_templates.form.sector'))
                ->options(self::sectorOptions())
                ->required()
                ->searchable()
                ->disabled($template !== null)
                ->helperText($template !== null ? __('message_templates.form.sector_locked') : __('message_templates.form.sector_help')),
            Select::make('type')->label(__('message_templates.form.type'))->options(self::typeOptions())->required()->helperText(__('message_templates.form.type_help')),
            TextInput::make('name')->label(__('message_templates.form.name'))->required()->maxLength(120)->autocomplete(false),
            Select::make('category')->label(__('message_templates.form.category'))->options(['utility' => __('message_templates.categories.utility'), 'marketing' => __('message_templates.categories.marketing')])
                ->helperText(__('message_templates.form.category_help')),
            Toggle::make('is_enabled')->label(__('message_templates.form.enabled'))->helperText(__('message_templates.form.enabled_help'))->default(true),
            Section::make(__('message_templates.form.translations'))
                ->description(__('message_templates.form.translations_help', ['variables' => $variables]))
                ->schema($fields),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('type')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('translations'))
            ->defaultGroup('sector_code')
            ->groups([
                Group::make('sector_code')
                    ->label(__('message_templates.columns.sector'))
                    ->getTitleFromRecordUsing(fn (PlatformMessageTemplate $record): string => self::sectorOptions()[$record->sector_code] ?? $record->sector_code)
                    ->collapsible(),
            ])
            ->columns([
                TextColumn::make('name')
                    ->label(__('message_templates.columns.name'))
                    ->description(fn (PlatformMessageTemplate $record): string => $record->meta_name)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $inner) use ($search): void {
                        $inner->where('name', 'ilike', "%{$search}%")->orWhere('meta_name', 'ilike', "%{$search}%")
                            ->orWhereHas('translations', fn (Builder $translations) => $translations->where('body', 'ilike', "%{$search}%"));
                    }))
                    ->sortable(),
                TextColumn::make('type')->label(__('message_templates.columns.type'))->formatStateUsing(fn (string $state): string => __("message_templates.types.{$state}"))->badge()->color('gray')->sortable(),
                TextColumn::make('category')->label(__('message_templates.columns.category'))->formatStateUsing(fn (string $state): string => __("message_templates.categories.{$state}"))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->label(__('message_templates.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("message_templates.statuses.{$state}"))
                    ->color(fn (string $state): string => ['draft' => 'gray', 'pending_approval' => 'warning', 'approved' => 'success', 'rejected' => 'danger', 'archived' => 'gray'][$state] ?? 'gray')
                    ->icon(fn (string $state): string => ['draft' => 'heroicon-m-pencil', 'pending_approval' => 'heroicon-m-clock', 'approved' => 'heroicon-m-check-circle', 'rejected' => 'heroicon-m-x-circle', 'archived' => 'heroicon-m-archive-box'][$state] ?? 'heroicon-m-minus-circle')
                    ->description(fn (PlatformMessageTemplate $record): ?string => $record->status === MessageTemplateStatus::REJECTED ? $record->rejection_reason : null)
                    ->sortable(),
                IconColumn::make('is_enabled')->label(__('message_templates.columns.enabled'))->boolean()->sortable(),
                TextColumn::make('languages')->label(__('message_templates.columns.languages'))
                    ->state(fn (PlatformMessageTemplate $record): string => $record->translations->pluck('language')->sort()->map(fn (string $language): string => strtoupper($language))->implode(' · ')),
                TextColumn::make('updated_at')->label(__('message_templates.columns.updated'))->dateTime('d/m/Y H:i T')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('sector_code')->label(__('message_templates.filters.sector'))->options(fn (): array => self::sectorOptions())->searchable(),
                SelectFilter::make('status')->label(__('message_templates.filters.status'))->options(self::statusOptions())->multiple(),
                SelectFilter::make('type')->label(__('message_templates.filters.type'))->options(self::typeOptions())->multiple(),
                SelectFilter::make('category')->label(__('message_templates.filters.category'))->options(['utility' => __('message_templates.categories.utility'), 'marketing' => __('message_templates.categories.marketing')]),
                Filter::make('language')
                    ->label(__('message_templates.filters.language'))
                    ->schema([Select::make('language')->label(__('message_templates.filters.language'))->options(self::languageOptions())->placeholder(__('message_templates.filters.all'))])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['language'] ?? null)
                        ? $query->whereHas('translations', fn (Builder $translations) => $translations->where('language', $data['language']))
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['language'] ?? null) ? __('message_templates.filters.language').' : '.strtoupper((string) $data['language']) : null),
                TernaryFilter::make('is_enabled')->label(__('message_templates.filters.enabled'))->placeholder(__('message_templates.filters.all'))
                    ->trueLabel(__('message_templates.filters.enabled_yes'))->falseLabel(__('message_templates.filters.enabled_no')),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label(__('message_templates.actions.preview'))
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (PlatformMessageTemplate $record): string => __('message_templates.preview.title', ['name' => $record->name]))
                    ->modalContent(fn (PlatformMessageTemplate $record) => view('admin.message-templates.preview', ['rows' => self::previewRows($record), 'template' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('message_templates.close')),
                Action::make('edit')
                    ->label(__('message_templates.actions.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (PlatformMessageTemplate $record): bool => AdminAccess::can('settings.edit') && $record->status !== MessageTemplateStatus::ARCHIVED)
                    ->modalHeading(fn (PlatformMessageTemplate $record): string => __('message_templates.edit_title', ['name' => $record->name]))
                    ->modalDescription(fn (PlatformMessageTemplate $record): ?string => in_array($record->status, [MessageTemplateStatus::APPROVED, MessageTemplateStatus::PENDING], true) ? __('message_templates.edit_resets') : null)
                    ->fillForm(fn (PlatformMessageTemplate $record): array => [
                        ...$record->only(['sector_code', 'type', 'name', 'category', 'is_enabled']),
                        'translations' => app(MessageTemplateManager::class)->bodies($record),
                    ])
                    ->schema(fn (PlatformMessageTemplate $record): array => self::formSchema($record))
                    ->action(fn (array $data, PlatformMessageTemplate $record) => self::attempt(
                        fn () => app(MessageTemplateManager::class)->update($record, $data, self::adminId()),
                        'message_templates.saved.updated',
                    )),
                ActionGroup::make([
                    Action::make('submit')
                        ->label(__('message_templates.actions.submit'))
                        ->icon('heroicon-o-paper-airplane')
                        ->visible(fn (PlatformMessageTemplate $record): bool => AdminAccess::can('settings.edit') && in_array($record->status, [MessageTemplateStatus::DRAFT, MessageTemplateStatus::REJECTED], true))
                        ->requiresConfirmation()
                        ->modalHeading(__('message_templates.confirm.submit_title'))
                        ->modalDescription(__('message_templates.confirm.submit_body'))
                        ->action(fn (PlatformMessageTemplate $record) => self::attempt(fn () => app(MessageTemplateManager::class)->submit($record, self::adminId()), 'message_templates.saved.submitted')),
                    Action::make('approve')
                        ->label(__('message_templates.actions.approve'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (PlatformMessageTemplate $record): bool => AdminAccess::can('settings.edit') && $record->status === MessageTemplateStatus::PENDING)
                        ->requiresConfirmation()
                        ->modalHeading(__('message_templates.confirm.approve_title'))
                        ->modalDescription(fn (PlatformMessageTemplate $record): string => __('message_templates.confirm.approve_body', ['sector' => self::sectorOptions()[$record->sector_code] ?? $record->sector_code]))
                        ->action(fn (PlatformMessageTemplate $record) => self::attempt(fn () => app(MessageTemplateManager::class)->approve($record, self::adminId()), 'message_templates.saved.approved')),
                    Action::make('reject')
                        ->label(__('message_templates.actions.reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (PlatformMessageTemplate $record): bool => AdminAccess::can('settings.edit') && $record->status === MessageTemplateStatus::PENDING)
                        ->modalHeading(__('message_templates.confirm.reject_title'))
                        ->schema([Textarea::make('reason')->label(__('message_templates.reject.reason'))->helperText(__('message_templates.reject.reason_help'))->required()->rows(3)->maxLength(500)])
                        ->action(fn (array $data, PlatformMessageTemplate $record) => self::attempt(fn () => app(MessageTemplateManager::class)->reject($record, (string) $data['reason'], self::adminId()), 'message_templates.saved.rejected')),
                    Action::make('duplicate')
                        ->label(__('message_templates.actions.duplicate'))
                        ->icon('heroicon-o-document-duplicate')
                        ->visible(fn (): bool => AdminAccess::can('settings.edit'))
                        ->modalHeading(__('message_templates.duplicate.title'))
                        ->modalDescription(__('message_templates.duplicate.help'))
                        ->fillForm(fn (PlatformMessageTemplate $record): array => ['sector_code' => $record->sector_code])
                        ->schema([Select::make('sector_code')->label(__('message_templates.duplicate.target'))->options(self::sectorOptions())->required()->searchable()])
                        ->action(fn (array $data, PlatformMessageTemplate $record) => self::attempt(fn () => app(MessageTemplateManager::class)->duplicate($record, (string) $data['sector_code'], self::adminId()), 'message_templates.saved.duplicated')),
                    Action::make('disable')
                        ->label(__('message_templates.actions.disable'))
                        ->icon('heroicon-o-pause-circle')
                        ->visible(fn (PlatformMessageTemplate $record): bool => AdminAccess::can('settings.edit') && $record->is_enabled)
                        ->action(fn (PlatformMessageTemplate $record) => self::attempt(fn () => app(MessageTemplateManager::class)->setEnabled($record, false, self::adminId()), 'message_templates.saved.disabled')),
                    Action::make('enable')
                        ->label(__('message_templates.actions.enable'))
                        ->icon('heroicon-o-play-circle')
                        ->visible(fn (PlatformMessageTemplate $record): bool => AdminAccess::can('settings.edit') && ! $record->is_enabled)
                        ->action(fn (PlatformMessageTemplate $record) => self::attempt(fn () => app(MessageTemplateManager::class)->setEnabled($record, true, self::adminId()), 'message_templates.saved.enabled')),
                    Action::make('archive')
                        ->label(__('message_templates.actions.archive'))
                        ->icon('heroicon-o-archive-box')
                        ->visible(fn (PlatformMessageTemplate $record): bool => AdminAccess::can('settings.edit') && $record->status !== MessageTemplateStatus::ARCHIVED)
                        ->requiresConfirmation()
                        ->modalHeading(__('message_templates.confirm.archive_title'))
                        ->modalDescription(__('message_templates.confirm.archive_body'))
                        ->action(fn (PlatformMessageTemplate $record) => self::attempt(fn () => app(MessageTemplateManager::class)->archive($record, self::adminId()), 'message_templates.saved.archived')),
                    Action::make('restore')
                        ->label(__('message_templates.actions.restore'))
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->visible(fn (PlatformMessageTemplate $record): bool => AdminAccess::can('settings.edit') && $record->status === MessageTemplateStatus::ARCHIVED)
                        ->requiresConfirmation()
                        ->modalHeading(__('message_templates.confirm.restore_title'))
                        ->modalDescription(__('message_templates.confirm.restore_body'))
                        ->action(fn (PlatformMessageTemplate $record) => self::attempt(fn () => app(MessageTemplateManager::class)->restore($record, self::adminId()), 'message_templates.saved.restored')),
                    Action::make('history')
                        ->label(__('message_templates.actions.history'))
                        ->icon('heroicon-o-clock')
                        ->modalHeading(fn (PlatformMessageTemplate $record): string => __('message_templates.history.title', ['name' => $record->name]))
                        ->modalContent(fn (PlatformMessageTemplate $record) => view('admin.message-templates.history', ['events' => self::history($record->id)]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('message_templates.close')),
                    Action::make('delete')
                        ->label(__('message_templates.actions.delete'))
                        ->icon('heroicon-o-trash')
                        ->color('danger')
                        ->visible(fn (PlatformMessageTemplate $record): bool => AdminAccess::can('settings.edit') && in_array($record->status, MessageTemplateStatus::DELETABLE, true))
                        ->requiresConfirmation()
                        ->modalHeading(fn (PlatformMessageTemplate $record): string => __('message_templates.confirm.delete_title', ['name' => $record->name]))
                        ->modalDescription(__('message_templates.confirm.delete_body'))
                        ->action(fn (PlatformMessageTemplate $record) => self::attempt(fn () => app(MessageTemplateManager::class)->delete($record, self::adminId()), 'message_templates.saved.deleted')),
                ]),
            ]);
    }

    /**
     * Texte de chaque langue rendu avec des données d'exemple, version Meta (variables numérotées) et problèmes éventuels.
     *
     * @return list<array{language: string, body: string, preview: string, meta: string, problems: list<string>}>
     */
    public static function previewRows(PlatformMessageTemplate $template): array
    {
        $manager = app(MessageTemplateManager::class);
        $rows = [];

        foreach ($manager->bodies($template) as $language => $body) {
            $samples = MessageVariables::sample($language, MessageTemplateLibrary::sampleService($template->sector_code, $language));
            $rows[] = [
                'language' => $language,
                'body' => $body,
                'preview' => MessageVariables::render($body, $samples),
                'meta' => MessageVariables::positional($body)['body'],
                'problems' => array_map(fn (string $problem): string => $manager->describe($problem), MessageVariables::problems($body)),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{when: string, action: string, from: string|null, to: string|null, reason: string|null, actor: string}>
     */
    public static function history(string $templateId): array
    {
        $events = MessageTemplateEvent::query()->where('template_id', $templateId)->orderByDesc('created_at')->limit(200)->get();
        $admins = DB::connection(PlatformAudit::CONNECTION)->table('platform_admins')->whereIn('id', $events->pluck('actor_id')->filter()->all())->pluck('email', 'id');

        return $events->map(fn (MessageTemplateEvent $event): array => [
            'when' => $event->created_at->setTimezone('UTC')->format('d/m/Y H:i T'),
            'action' => __("message_templates.events.{$event->action}"),
            'from' => $event->from_status === null ? null : __("message_templates.statuses.{$event->from_status}"),
            'to' => $event->to_status === null ? null : __("message_templates.statuses.{$event->to_status}"),
            'reason' => $event->reason,
            'actor' => $event->actor_id === null ? __('message_templates.history.system') : (string) ($admins[$event->actor_id] ?? __('message_templates.history.unknown')),
        ])->all();
    }

    /**
     * Exécute une opération du gestionnaire et dit le résultat ; un refus métier (mauvais état, texte invalide…) s'affiche tel quel.
     */
    public static function attempt(Closure $operation, string $successKey): void
    {
        try {
            $operation();
        } catch (DomainException|InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__($successKey))->send();
    }

    public static function adminId(): string
    {
        return (string) (Filament::auth()->user() ?? abort(403))->getAuthIdentifier();
    }

    public static function getPages(): array
    {
        return ['index' => ListMessageTemplates::route('/')];
    }
}
