<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources;

use App\Modules\Admin\Filament\Resources\PlatformSettingResource\Pages\ListPlatformSettings;
use App\Modules\Admin\Filament\Support\SensitiveActionFields;
use App\Modules\Admin\Models\PlatformSetting;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\PlatformSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Paramètres globaux (docs/02 §3.1) : durées de suppression, de pause et de suspension. Modification = action sensible
 * (motif, mot de passe, code 2FA) tracée avec l'ancienne et la nouvelle valeur.
 */
class PlatformSettingResource extends Resource
{
    protected static ?string $model = PlatformSetting::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 4;

    public static function getModelLabel(): string
    {
        return __('admin.settings.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.settings.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.settings.plural');
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
            ->defaultSort('key')
            ->paginated(false)
            ->columns([
                TextColumn::make('key')->label(__('admin.settings.name'))->formatStateUsing(fn (string $state): string => __("admin.settings.keys.{$state}.label")),
                TextColumn::make('value')->label(__('admin.settings.value'))->formatStateUsing(fn (string $state, PlatformSetting $record): string => trans_choice('admin.settings.'.($record->key === 'dunning_max_attempts' ? 'attempts' : 'days'), (int) json_decode($state, true))),
                TextColumn::make('description')->label(__('admin.settings.description'))->state(fn (PlatformSetting $record): string => __("admin.settings.keys.{$record->key}.help"))->wrap(),
                TextColumn::make('updated_at')->label(__('admin.settings.updated_at'))->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('admin.settings.edit'))
                    ->modalHeading(fn (PlatformSetting $record): string => __("admin.settings.keys.{$record->key}.label"))
                    ->fillForm(fn (PlatformSetting $record): array => ['value' => (int) json_decode($record->value, true)])
                    ->schema(fn (PlatformSetting $record): array => [
                        TextInput::make('value')->label(__('admin.settings.value'))->numeric()->integer()->required()
                            ->minValue(PlatformSettings::INTEGERS[$record->key]['min'])->maxValue(PlatformSettings::INTEGERS[$record->key]['max']),
                        ...SensitiveActionFields::make(),
                    ])
                    ->action(function (array $data, PlatformSetting $record, Action $action): void {
                        [$adminId, $reason] = SensitiveActionFields::authorize($action, $data);

                        $changes = app(PlatformSettings::class)->update([$record->key => (int) $data['value']], $adminId);

                        if ($changes !== []) {
                            PlatformAudit::record($adminId, 'platform_settings.updated', null, 'platform_settings', null, $changes, $reason);
                        }

                        Notification::make()->success()->title(__('admin.settings.saved'))->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPlatformSettings::route('/')];
    }
}
