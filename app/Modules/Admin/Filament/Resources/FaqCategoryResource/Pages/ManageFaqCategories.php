<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\FaqCategoryResource\Pages;

use App\Modules\Admin\Filament\Resources\FaqCategoryResource;
use App\Modules\Admin\Filament\Support\FaqActions;
use App\Modules\Admin\Services\FaqCategoryManager;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;

/**
 * Catégories du centre d'aide : liste et création (les modifications passent par FaqCategoryManager, avec audit).
 */
class ManageFaqCategories extends ManageRecords
{
    protected static string $resource = FaqCategoryResource::class;

    public function getTitle(): string
    {
        return __('faq.categories.plural');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label(__('faq.categories.create'))->icon('heroicon-m-plus')->modalHeading(__('faq.categories.create'))
                ->schema(FaqCategoryResource::formSchema(true))
                ->action(fn (array $data) => FaqActions::perform(fn ($admin) => app(FaqCategoryManager::class)->create($admin, $data), __('faq.categories.created'))),
        ];
    }
}
