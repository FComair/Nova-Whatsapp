<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Modules\Admin\Filament\Support\SuperAdminOnly;
use App\Modules\Admin\Models\PlatformAuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'audit en lecture seule (docs/02 §3.1, « Journal »). Les données personnelles n'y figurent jamais :
 * seulement l'action, l'acteur, le motif et les champs modifiés.
 */
class AuditLogResource extends Resource
{
    use SuperAdminOnly;

    protected static ?string $model = PlatformAuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 7;

    public static function getModelLabel(): string
    {
        return __('admin.audit.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.audit.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.audit.plural');
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

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.audit.when'))->dateTime()->sortable(),
                TextColumn::make('actor_type')->label(__('admin.audit.actor'))->badge(),
                TextColumn::make('action')->label(__('admin.audit.action'))->searchable(),
                TextColumn::make('org_id')->label(__('admin.audit.organization'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('reason')->label(__('admin.audit.reason'))->limit(60)->placeholder('—'),
                TextColumn::make('ip')->label(__('admin.audit.ip'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('actor_type')->label(__('admin.audit.actor'))->options(['platform_admin' => 'platform_admin', 'user' => 'user', 'system' => 'system', 'api' => 'api', 'staff_whatsapp' => 'staff_whatsapp']),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
