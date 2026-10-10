<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\FeedbackResource\Pages\ListFeedback;
use App\Modules\Admin\Filament\Support\HiddenFromSupportAgents;
use App\Modules\Admin\Models\PlatformFeedback;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Avis PRIVÉS des clients sur Nova (docs/26), en lecture seule : note, commentaire, date, origine (général, après une réponse du support,
 * ticket), référence du ticket, compte client et plateformes d'avis public dont le client a ouvert le lien. Séparé de tout avis public :
 * ces notes ne sont jamais présentées comme des notes Google ou Trustpilot.
 */
class FeedbackResource extends Resource
{
    use HiddenFromSupportAgents;

    protected static ?string $model = PlatformFeedback::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-star';

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('admin.feedback.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.feedback.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.feedback.navigation');
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

    /** @return Builder<Model> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->leftJoin('support_tickets as ticket', 'ticket.id', '=', 'nova_feedback.ticket_id')
            ->leftJoin('organizations as org', 'org.id', '=', 'nova_feedback.org_id')
            ->select('nova_feedback.*', 'ticket.reference_number as ticket_number', 'org.name as organization_name')
            ->addSelect(DB::raw("(select string_agg(distinct c.platform, ',' order by c.platform) from review_link_clicks c where c.feedback_id = nova_feedback.id) as clicked_platforms"));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.feedback.created_at'))->dateTime()->sortable(),
                TextColumn::make('rating')->label(__('admin.feedback.rating'))
                    ->formatStateUsing(fn (int $state): string => str_repeat('★', $state).str_repeat('☆', 5 - $state).' '.$state.'/5')
                    ->sortable(),
                TextColumn::make('comment')->label(__('admin.feedback.comment'))->placeholder(__('admin.feedback.no_comment'))->wrap()->limit(160)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('nova_feedback.comment', 'ilike', '%'.addcslashes($search, '\\%_').'%')),
                TextColumn::make('source')->label(__('admin.feedback.source'))->badge()
                    ->formatStateUsing(fn (string $state): string => __("admin.feedback.sources.{$state}"))
                    ->color(fn (string $state): string => $state === 'general' ? 'gray' : 'info'),
                TextColumn::make('ticket_number')->label(__('admin.feedback.ticket'))->formatStateUsing(fn (?int $state): string => $state === null ? '—' : 'NWS-'.$state),
                TextColumn::make('organization_name')->label(__('admin.feedback.organization'))->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('org.name', 'ilike', '%'.addcslashes($search, '\\%_').'%')),
                TextColumn::make('clicked_platforms')->label(__('admin.feedback.clicks'))
                    ->formatStateUsing(fn (?string $state): string => $state === null || $state === '' ? __('admin.feedback.none') : collect(explode(',', $state))->map(fn (string $platform): string => __("admin.feedback.platforms.{$platform}"))->implode(', ')),
            ])
            ->filters([
                SelectFilter::make('source')->label(__('admin.feedback.source'))->options(fn (): array => collect(['general', 'intervention', 'ticket'])->mapWithKeys(fn (string $source): array => [$source => __("admin.feedback.sources.{$source}")])->all()),
                SelectFilter::make('rating')->label(__('admin.feedback.rating'))->options(fn (): array => collect(range(1, 5))->mapWithKeys(fn (int $rating): array => [$rating => __("admin.feedback.ratings.{$rating}")])->all()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedback::route('/')];
    }
}
