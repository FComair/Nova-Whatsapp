<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\PlanResource\Pages;

use App\Modules\Admin\Filament\Resources\PlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    public function getSubheading(): ?string
    {
        return __('plans.subtitle');
    }

    /**
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('plans.actions.create'))->icon('heroicon-o-plus')];
    }
}
