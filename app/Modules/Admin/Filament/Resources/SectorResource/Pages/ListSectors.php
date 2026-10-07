<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\SectorResource\Pages;

use App\Modules\Admin\Filament\Resources\SectorResource;
use App\Modules\Admin\Services\SectorManager;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSectors extends ListRecords
{
    protected static string $resource = SectorResource::class;

    public function getSubheading(): ?string
    {
        return __('admin.sectors.subtitle');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('admin.sectors.add'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('admin.sectors.add'))
                ->modalSubmitActionLabel(__('admin.sectors.add'))
                ->schema(SectorResource::formSchema())
                ->action(function (array $data): void {
                    app(SectorManager::class)->create($data, (string) (Filament::auth()->user() ?? abort(403))->getAuthIdentifier());

                    Notification::make()->success()->title(__('admin.sectors.saved.created'))->send();
                }),
        ];
    }
}
