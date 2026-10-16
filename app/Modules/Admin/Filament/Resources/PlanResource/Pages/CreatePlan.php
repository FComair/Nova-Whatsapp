<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\PlanResource\Pages;

use App\Modules\Admin\Filament\Resources\PlanResource;
use App\Modules\Admin\Services\PlanManager;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Création d'une formule : elle naît en brouillon (ou dans le statut choisi s'il est publiable) et n'est visible d'aucun client tant qu'elle n'est pas active.
 */
class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('plans.actions.create');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(PlanManager::class)->create($data, PlanResource::adminId());
        } catch (DomainException|InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('plans.saved.created');
    }

    protected function getRedirectUrl(): string
    {
        return PlanResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
