<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\DeletionRequestResource\Pages\ListDeletionRequests;
use App\Modules\Admin\Filament\Support\SensitiveActionFields;
use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Privacy\Models\DeletionRequest;
use App\Modules\Privacy\Services\DeletionPipeline;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Suivi des suppressions de comptes (docs/02 §3.1, RGPD) : avancement par table, échecs (E12) et relance.
 * Aucune donnée personnelle : identifiant d'organisation, étapes, nombres de lignes et codes d'erreur.
 */
class DeletionRequestResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = DeletionRequest::class;

    /** Permission nécessaire pour voir cet écran (docs/38). */
    protected static ?string $viewPermission = 'clients.view';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-trash';

    protected static ?int $navigationSort = 6;

    public static function getModelLabel(): string
    {
        return __('admin.deletions.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.deletions.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.deletions.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.navigation.operations');
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

    /** État global d'une suppression : `completed`, `failed` (une étape a épuisé ses tentatives), `running` ou `pending`. */
    public static function stateOf(DeletionRequest $request): string
    {
        if ($request->completed_at !== null) {
            return 'completed';
        }

        if ($request->jobs()->where('status', 'failed')->exists()) {
            return 'failed';
        }

        return $request->started_at === null ? 'pending' : 'running';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_for', 'desc')
            ->columns([
                TextColumn::make('organization.name')->label(__('admin.deletions.organization'))->placeholder('—'),
                TextColumn::make('kind')->label(__('admin.deletions.kind'))->badge()->formatStateUsing(fn (string $state): string => __("admin.deletions.kinds.{$state}")),
                TextColumn::make('state')->label(__('admin.deletions.state'))->badge()
                    ->state(fn (DeletionRequest $record): string => self::stateOf($record))
                    ->formatStateUsing(fn (string $state): string => __("admin.deletions.states.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('progress')->label(__('admin.deletions.progress'))
                    ->state(fn (DeletionRequest $record): string => $record->jobs()->where('status', 'done')->count().' / '.$record->jobs()->count()),
                TextColumn::make('failure')->label(__('admin.deletions.failure'))->placeholder('—')
                    ->state(fn (DeletionRequest $record): ?string => ($job = $record->jobs()->where('status', 'failed')->first()) === null ? null : "{$job->step} ({$job->last_error})"),
                TextColumn::make('scheduled_for')->label(__('admin.deletions.scheduled_for'))->dateTime()->sortable(),
                TextColumn::make('completed_at')->label(__('admin.deletions.completed_at'))->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label(__('admin.deletions.retry'))
                    ->color('warning')
                    ->modalHeading(__('admin.deletions.retry'))
                    ->modalDescription(__('admin.deletions.retry_description'))
                    ->visible(fn (DeletionRequest $record): bool => self::stateOf($record) === 'failed' && AdminAccess::can('clients.delete'))
                    ->schema(SensitiveActionFields::make())
                    ->action(function (array $data, DeletionRequest $record, Action $action): void {
                        [$adminId, $reason] = SensitiveActionFields::authorize($action, $data);

                        $count = app(DeletionPipeline::class)->retry($record);
                        PlatformAudit::record($adminId, 'gdpr.retry_requested', $record->org_id, 'organizations', $record->org_id, ['jobs' => $count], $reason);

                        Notification::make()->success()->title(__('admin.deletions.retried'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDeletionRequests::route('/')];
    }
}
