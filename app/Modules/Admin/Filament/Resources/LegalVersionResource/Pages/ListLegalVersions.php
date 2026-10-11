<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\LegalVersionResource\Pages;

use App\Modules\Admin\Filament\Resources\LegalVersionResource;
use App\Modules\Admin\Services\LegalVersionManager;
use App\Modules\Admin\Support\AdminAccess;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListLegalVersions extends ListRecords
{
    protected static string $resource = LegalVersionResource::class;

    public function getSubheading(): ?string
    {
        return __('admin.legal.subtitle');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->visible(fn (): bool => AdminAccess::can('legal.upload'))
                ->label(__('admin.legal.add'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('admin.legal.add'))
                ->modalSubmitActionLabel(__('admin.legal.add'))
                ->schema(LegalVersionResource::formSchema())
                ->action(fn (array $data) => LegalVersionResource::attempt(fn () => app(LegalVersionManager::class)->create($data, LegalVersionResource::adminId()), 'admin.legal.saved.created')),
        ];
    }
}
