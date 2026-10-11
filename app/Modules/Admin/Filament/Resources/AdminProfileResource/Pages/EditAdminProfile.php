<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages;

use App\Modules\Admin\Exceptions\AdminRefused;
use App\Modules\Admin\Filament\Resources\AdminProfileResource;
use App\Modules\Admin\Filament\Support\AdminProfileActions;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\AdminTeam;
use App\Modules\Admin\Support\AdminPermissions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * Modification d'un administrateur : profil, rôle et permissions. Changer le rôle ou les permissions demande une confirmation qui dit ce qui va
 * changer ; on ne se retire pas son propre accès (refusé par `AdminTeam`).
 */
class EditAdminProfile extends EditRecord
{
    protected static string $resource = AdminProfileResource::class;

    public function getTitle(): string|Htmlable
    {
        $admin = $this->getRecord();

        return __('admins.actions.edit').' : '.($admin instanceof PlatformAdmin ? $admin->getFilamentName() : '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $admin = $this->getRecord();

        return $admin instanceof PlatformAdmin ? AdminProfileResource::formState($admin) : $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $admin = $record instanceof PlatformAdmin ? $record : abort(404);

        try {
            app(AdminTeam::class)->update(AdminProfileActions::admin(), $admin, [
                'name' => (string) $data['name'], 'phone' => $data['phone'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null,
                'role' => (string) $data['role'], 'permissions' => AdminProfileResource::chosenPermissions($data),
            ]);
        } catch (AdminRefused $refused) {
            Notification::make()->danger()->title(__('admins.errors.title'))->body($refused->message())->persistent()->send();

            throw new Halt;
        }

        return $admin->refresh();
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return __('admins.saved');
    }

    protected function getRedirectUrl(): ?string
    {
        return AdminProfileResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    /** Confirmation quand le rôle ou les permissions changent : c'est ce qui ouvre ou ferme des accès. */
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation(fn (): bool => $this->accessWillChange())
            ->modalHeading(__('admins.access_change.heading'))->modalDescription(fn (): string => __('admins.access_change.impact'))->modalIcon('heroicon-o-exclamation-triangle');
    }

    private function accessWillChange(): bool
    {
        $admin = $this->getRecord();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        $state = $this->form->getRawState();
        $role = (string) ($state['role'] ?? '');
        $after = AdminPermissions::forRole($role, AdminProfileResource::chosenPermissions($state));
        $before = AdminPermissions::forRole($admin->adminRole(), $admin->permissions);

        return $role !== $admin->adminRole() || array_diff($after, $before) !== [] || array_diff($before, $after) !== [];
    }
}
