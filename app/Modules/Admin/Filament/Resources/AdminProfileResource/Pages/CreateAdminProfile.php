<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\AdminProfileResource\Pages;

use App\Modules\Admin\Exceptions\AdminRefused;
use App\Modules\Admin\Filament\Resources\AdminProfileResource;
use App\Modules\Admin\Filament\Support\AdminProfileActions;
use App\Modules\Admin\Services\AdminTeam;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

/**
 * Création d'un administrateur : le compte naît « invité » et reçoit un lien à usage unique pour choisir son mot de passe. Aucune saisie
 * directe d'identifiants.
 */
class CreateAdminProfile extends CreateRecord
{
    protected static string $resource = AdminProfileResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('admins.actions.create');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(AdminTeam::class)->create(AdminProfileActions::admin(), [
                'name' => (string) $data['name'], 'email' => (string) $data['email'], 'phone' => $data['phone'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null,
                'role' => (string) $data['role'], 'permissions' => AdminProfileResource::chosenPermissions($data),
            ]);
        } catch (AdminRefused $refused) {
            Notification::make()->danger()->title(__('admins.errors.title'))->body($refused->message())->send();

            throw new Halt;
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('admins.create.done');
    }

    protected function getRedirectUrl(): string
    {
        return AdminProfileResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
