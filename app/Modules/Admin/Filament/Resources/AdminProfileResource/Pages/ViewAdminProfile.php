<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages;

use App\Modules\Admin\Filament\Resources\AdminProfileResource;
use App\Modules\Admin\Filament\Support\AdminProfileActions;
use App\Modules\Admin\Models\PlatformAdmin;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewAdminProfile extends ViewRecord
{
    protected static string $resource = AdminProfileResource::class;

    public function getTitle(): string|Htmlable
    {
        $admin = $this->getRecord();

        return $admin instanceof PlatformAdmin ? $admin->getFilamentName() : __('admins.singular');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $record = fn () => $this->getRecord();

        return [
            AdminProfileActions::edit()->record($record),
            AdminProfileActions::resendInvitation()->record($record),
            AdminProfileActions::resetPassword()->record($record),
            AdminProfileActions::forceLogout()->record($record),
            AdminProfileActions::suspend()->record($record),
            AdminProfileActions::reactivate()->record($record),
            AdminProfileActions::remove()->record($record),
        ];
    }
}
