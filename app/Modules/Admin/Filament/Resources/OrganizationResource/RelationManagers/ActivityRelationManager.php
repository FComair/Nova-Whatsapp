<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\OrganizationResource\RelationManagers;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Admin\Services\ClientProfile;
use App\Modules\Admin\Services\PlatformAudit;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Journal d'activité d'un client (docs/31 §7) : ce que le client, l'équipe Rendivoo ou le système ont fait sur son compte (journal d'audit,
 * en ajout seul). Filtres : type d'événement, période, acteur, personne ; recherche dans l'action, le motif et les valeurs.
 */
class ActivityRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    /** Familles d'événements proposées en filtre : préfixe de l'action. */
    private const FAMILIES = ['auth', 'billing', 'organization', 'client', 'whatsapp', 'location', 'org', 'legal', 'customer', 'support', 'faq'];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('clients.activity.title');
    }

    public function table(Table $table): Table
    {
        $owner = $this->getOwnerRecord();
        $members = $owner instanceof PlatformOrganization ? collect(app(ClientProfile::class)->members($owner))->pluck('email', 'user_id')->all() : [];
        $admins = DB::connection(PlatformAudit::CONNECTION)->table('platform_admins')->pluck('email', 'id')->all();

        return $table
            ->defaultSort('created_at', 'desc')
            ->heading(__('clients.activity.title'))
            ->emptyStateHeading(__('clients.activity.empty'))
            ->columns([
                TextColumn::make('created_at')->label(__('clients.activity.when'))->dateTime('d/m/Y H:i T', (string) ($owner->getAttribute('timezone') ?: 'UTC'))->sortable(),
                TextColumn::make('action')->label(__('clients.activity.event'))->searchable()
                    ->formatStateUsing(fn (string $state): string => self::label($state))->description(fn (Model $record): string => (string) $record->getAttribute('action')),
                TextColumn::make('actor_type')->label(__('clients.activity.actor'))
                    ->state(fn (Model $record): string => match ($record->getAttribute('actor_type')) {
                        'platform_admin' => (string) ($admins[$record->getAttribute('actor_id')] ?? __('clients.activity.actors.platform_admin')),
                        'user' => (string) ($members[$record->getAttribute('actor_id')] ?? __('clients.activity.actors.user')),
                        default => __('clients.activity.actors.'.$record->getAttribute('actor_type')),
                    }),
                TextColumn::make('diff')->label(__('clients.activity.detail'))->wrap()->limit(120)
                    ->state(fn (Model $record): string => self::detail($record)),
                TextColumn::make('entity_type')->label(__('clients.activity.entity'))->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (Model $record): string => trim($record->getAttribute('entity_type').' '.$record->getAttribute('entity_id'))),
                TextColumn::make('ip')->label(__('clients.activity.ip'))->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
                TextColumn::make('user_agent')->label(__('clients.activity.agent'))->toggleable(isToggledHiddenByDefault: true)->limit(40)->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('family')->label(__('clients.activity.family'))
                    ->options(fn (): array => collect(self::FAMILIES)->mapWithKeys(fn (string $family): array => [$family => __("clients.activity.families.{$family}")])->all())
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $family) => $q->where('action', 'like', $family.'.%'))),
                SelectFilter::make('actor_type')->label(__('clients.activity.actor'))
                    ->options(fn (): array => collect(['user', 'platform_admin', 'system', 'api', 'staff_whatsapp'])->mapWithKeys(fn (string $type): array => [$type => __("clients.activity.actors.{$type}")])->all()),
                SelectFilter::make('person')->label(__('clients.activity.person'))->options($members)
                    ->query(fn (Builder $query, array $data): Builder => $query->when($data['value'] ?? null, fn (Builder $q, string $id) => $q->where('actor_id', $id))),
                Filter::make('dates')->label(__('clients.activity.period'))
                    ->schema([DatePicker::make('from')->label(__('clients.filters.from'))->native(false)->displayFormat('d/m/Y'), DatePicker::make('until')->label(__('clients.filters.until'))->native(false)->displayFormat('d/m/Y')])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date) => $q->where('created_at', '>=', $date.' 00:00:00'))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date) => $q->where('created_at', '<=', $date.' 23:59:59'))),
            ]);
    }

    /** Libellé lisible d'une action (clients.events.*), sinon l'action brute. */
    public static function label(string $action): string
    {
        $key = 'clients.events.'.str_replace('.', '_', $action);
        $label = __($key);

        return $label === $key ? $action : $label;
    }

    /** Résumé des valeurs avant / après, du motif et des données utiles. */
    private static function detail(Model $record): string
    {
        $diff = (array) $record->getAttribute('diff');
        $parts = [];

        foreach ($diff as $key => $value) {
            if (is_array($value) && count($value) === 2 && ! is_array($value[0] ?? null) && ! is_array($value[1] ?? null)) {
                $parts[] = "{$key}: ".($value[0] ?? '—').' → '.($value[1] ?? '—');
            } elseif (is_scalar($value)) {
                $parts[] = "{$key}: {$value}";
            }
        }

        if ($record->getAttribute('reason') !== null) {
            $parts[] = __('clients.activity.reason', ['reason' => $record->getAttribute('reason')]);
        }

        return implode(' · ', $parts);
    }
}
