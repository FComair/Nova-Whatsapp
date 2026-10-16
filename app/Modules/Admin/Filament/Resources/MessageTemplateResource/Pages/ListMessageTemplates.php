<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\MessageTemplateResource\Pages;

use App\Modules\Admin\Filament\Resources\MessageTemplateResource;
use App\Modules\Admin\Services\MessageTemplateImporter;
use App\Modules\Admin\Services\MessageTemplateManager;
use App\Modules\Admin\Support\AdminAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListMessageTemplates extends ListRecords
{
    protected static string $resource = MessageTemplateResource::class;

    public function getSubheading(): ?string
    {
        return __('message_templates.subtitle');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->visible(fn (): bool => AdminAccess::can('settings.edit'))
                ->label(__('message_templates.actions.import'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->modalHeading(__('message_templates.import.title'))
                ->modalDescription(__('message_templates.import.help'))
                ->modalSubmitActionLabel(__('message_templates.actions.import'))
                ->schema([
                    Select::make('sector_code')->label(__('message_templates.import.sector'))->options(MessageTemplateResource::sectorOptions())->searchable()
                        ->placeholder(__('message_templates.import.all_sectors')),
                ])
                ->action(function (array $data): void {
                    $result = app(MessageTemplateImporter::class)->import(filled($data['sector_code'] ?? null) ? (string) $data['sector_code'] : null, MessageTemplateResource::adminId());

                    Notification::make()->success()->title(__('message_templates.saved.imported', $result))->send();
                }),
            Action::make('create')->visible(fn (): bool => AdminAccess::can('settings.edit'))
                ->label(__('message_templates.actions.create'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('message_templates.actions.create'))
                ->modalSubmitActionLabel(__('message_templates.actions.create'))
                ->schema(MessageTemplateResource::formSchema())
                ->action(fn (array $data) => MessageTemplateResource::attempt(
                    fn () => app(MessageTemplateManager::class)->create($data, MessageTemplateResource::adminId()),
                    'message_templates.saved.created',
                )),
        ];
    }
}
