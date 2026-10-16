<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\PlanResource\Pages;

use App\Modules\Admin\Filament\Resources\PlanResource;
use App\Modules\Admin\Models\PlatformPlan;
use App\Modules\Admin\Services\PlanManager;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Modification d'une formule. Si le changement est commercial et que la formule a des abonnés, une nouvelle version est créée : l'écran
 * l'annonce et ouvre la version en vigueur (les abonnés actuels gardent l'ancienne, avec son prix et ses limites).
 */
class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    private bool $versioned = false;

    public function getTitle(): string|Htmlable
    {
        return __('plans.actions.edit').' — '.$this->getRecord()->getAttribute('name');
    }

    public function getSubheading(): ?string
    {
        $plan = $this->getRecord();
        $subscribers = $plan instanceof PlatformPlan ? app(PlanManager::class)->subscribers($plan) : 0;

        return $subscribers > 0 ? __('plans.form.versioning_notice', ['count' => $subscribers]) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $plan = $record instanceof PlatformPlan ? $record : abort(404);

        try {
            $updated = app(PlanManager::class)->update($plan, $data, PlanResource::adminId());
        } catch (DomainException|InvalidArgumentException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();

            throw new Halt;
        }

        $this->versioned = $updated->id !== $plan->id;

        if ($this->versioned) {
            $this->redirect(PlanResource::getUrl('edit', ['record' => $updated]));
        }

        return $updated;
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __($this->versioned ? 'plans.saved.versioned' : 'plans.saved.updated');
    }

    protected function getRedirectUrl(): ?string
    {
        return null;
    }
}
