<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages;

use App\Modules\Admin\Filament\Resources\AdminProfileResource;
use App\Modules\Admin\Filament\Support\AdminProfileActions;
use App\Modules\Admin\Services\AdminTeam;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListAdminProfiles extends ListRecords
{
    protected static string $resource = AdminProfileResource::class;

    public function getTitle(): string
    {
        return __('admins.title');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('admins.actions.create'))->icon('heroicon-m-user-plus')->visible(fn (): bool => AdminProfileResource::canCreate())
                ->url(fn (): string => AdminProfileResource::getUrl('create')),
            Action::make('export')->label(__('admins.actions.export'))->icon('heroicon-m-arrow-down-tray')->color('gray')
                ->action(fn (): StreamedResponse => app(AdminTeam::class)->export(AdminProfileActions::admin(), $this->getFilteredSortedTableQuery())),
        ];
    }
}
