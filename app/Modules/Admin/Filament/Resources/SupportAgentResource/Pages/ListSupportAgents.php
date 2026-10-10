<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\SupportAgentResource\Pages;

use App\Modules\Admin\Filament\Resources\SupportAgentResource;
use App\Modules\Admin\Filament\Support\SupportTeamActions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSupportAgents extends ListRecords
{
    protected static string $resource = SupportAgentResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [SupportTeamActions::create()];
    }
}
