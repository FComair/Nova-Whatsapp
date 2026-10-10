<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\SupportAgentResource\Pages;

use App\Modules\Admin\Filament\Resources\SupportAgentResource;
use App\Modules\Admin\Filament\Support\SupportTeamActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewSupportAgent extends ViewRecord
{
    protected static string $resource = SupportAgentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            SupportTeamActions::edit()->record(fn () => $this->getRecord()),
            SupportTeamActions::resendInvitation()->record(fn () => $this->getRecord()),
            SupportTeamActions::resetPassword()->record(fn () => $this->getRecord()),
            SupportTeamActions::suspend()->record(fn () => $this->getRecord()),
            SupportTeamActions::reactivate()->record(fn () => $this->getRecord()),
            SupportTeamActions::remove()->record(fn () => $this->getRecord()),
        ];
    }
}
