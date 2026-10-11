<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Admin\Exceptions\AdminRefused;
use App\Modules\Admin\Filament\Resources\AdminProfileResource;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\AdminTeam;
use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\SupportAccess;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * Actions sur un compte administrateur (docs/38), partagées par la liste et la fiche. Chacune explique son effet avant la confirmation, passe
 * par `AdminTeam` (qui contrôle le droit, les règles de sécurité et l'audit) et transforme un refus en message, jamais en erreur serveur.
 */
final class AdminProfileActions
{
    public static function admin(): PlatformAdmin
    {
        return SupportAccess::admin() ?? abort(403);
    }

    public static function target(Model $record): PlatformAdmin
    {
        return $record instanceof PlatformAdmin ? $record : abort(404);
    }

    public static function perform(callable $operation, string $success): bool
    {
        try {
            $operation();
        } catch (AdminRefused $e) {
            Notification::make()->danger()->title(__('admins.errors.title'))->body($e->message())->send();

            return false;
        }

        Notification::make()->success()->title($success)->send();

        return true;
    }

    public static function edit(): Action
    {
        return Action::make('edit')->label(__('admins.actions.edit'))->icon('heroicon-m-pencil-square')
            ->visible(fn (Model $record): bool => AdminAccess::can('admins.edit') && self::target($record)->status !== PlatformAdmin::STATUS_REMOVED)
            ->url(fn (Model $record): string => AdminProfileResource::getUrl('edit', ['record' => $record->getKey()]));
    }

    public static function resendInvitation(): Action
    {
        return Action::make('resend_invitation')->label(__('admins.actions.resend'))->icon('heroicon-m-paper-airplane')
            ->visible(fn (Model $record): bool => AdminAccess::can('admins.edit') && self::target($record)->status === PlatformAdmin::STATUS_INVITED)
            ->requiresConfirmation()->modalHeading(__('admins.actions.resend'))->modalDescription(__('admins.resend.impact'))
            ->action(fn (Model $record) => self::perform(fn () => app(AdminTeam::class)->resendInvitation(self::admin(), self::target($record)), __('admins.resend.done')));
    }

    public static function resetPassword(): Action
    {
        return Action::make('reset_password')->label(__('admins.actions.reset_password'))->icon('heroicon-m-key')
            ->visible(fn (Model $record): bool => AdminAccess::can('admins.edit') && self::target($record)->status === PlatformAdmin::STATUS_ACTIVE && self::target($record)->id !== self::admin()->id)
            ->requiresConfirmation()->modalHeading(__('admins.actions.reset_password'))->modalDescription(__('admins.reset.impact'))
            ->action(fn (Model $record) => self::perform(fn () => app(AdminTeam::class)->sendPasswordReset(self::admin(), self::target($record)), __('admins.reset.done')));
    }

    public static function forceLogout(): Action
    {
        return Action::make('force_logout')->label(__('admins.actions.force_logout'))->icon('heroicon-m-arrow-right-start-on-rectangle')->color('warning')
            ->visible(fn (Model $record): bool => AdminAccess::can('admins.edit') && self::target($record)->status !== PlatformAdmin::STATUS_REMOVED && self::target($record)->id !== self::admin()->id)
            ->requiresConfirmation()->modalHeading(__('admins.actions.force_logout'))->modalDescription(__('admins.logout.impact'))->modalIcon('heroicon-o-exclamation-triangle')
            ->action(fn (Model $record) => self::perform(fn () => app(AdminTeam::class)->forceLogout(self::admin(), self::target($record)), __('admins.logout.done')));
    }

    public static function suspend(): Action
    {
        return self::withReason('suspend', 'heroicon-m-pause-circle', [PlatformAdmin::STATUS_ACTIVE], fn (AdminTeam $team, PlatformAdmin $admin, ?string $reason) => $team->suspend(self::admin(), $admin, $reason));
    }

    public static function remove(): Action
    {
        return self::withReason('remove', 'heroicon-m-user-minus', [PlatformAdmin::STATUS_ACTIVE, PlatformAdmin::STATUS_INVITED, PlatformAdmin::STATUS_SUSPENDED], fn (AdminTeam $team, PlatformAdmin $admin, ?string $reason) => $team->remove(self::admin(), $admin, $reason));
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')->label(__('admins.actions.reactivate'))->icon('heroicon-m-play-circle')->color('success')
            ->visible(fn (Model $record): bool => AdminAccess::can('admins.suspend') && in_array(self::target($record)->status, [PlatformAdmin::STATUS_SUSPENDED, PlatformAdmin::STATUS_REMOVED], true))
            ->requiresConfirmation()->modalHeading(__('admins.actions.reactivate'))->modalDescription(__('admins.reactivate.impact'))
            ->action(fn (Model $record) => self::perform(fn () => app(AdminTeam::class)->reactivate(self::admin(), self::target($record)), __('admins.reactivate.done')));
    }

    /**
     * Suspension et retrait : l'effet est expliqué, un motif facultatif est demandé, puis la personne confirme.
     *
     * @param  list<string>  $statuses
     * @param  callable(AdminTeam, PlatformAdmin, ?string): mixed  $operation
     */
    private static function withReason(string $name, string $icon, array $statuses, callable $operation): Action
    {
        $permission = $name === 'remove' ? 'admins.delete' : 'admins.suspend';

        return Action::make($name)->label(__("admins.actions.{$name}"))->icon($icon)->color('danger')
            ->visible(fn (Model $record): bool => AdminAccess::can($permission) && in_array(self::target($record)->status, $statuses, true) && self::target($record)->id !== self::admin()->id)
            ->modalHeading(__("admins.{$name}.heading"))->modalDescription(__("admins.{$name}.impact"))->modalIcon('heroicon-o-exclamation-triangle')
            ->modalSubmitActionLabel(__("admins.actions.{$name}"))
            ->schema([Textarea::make('reason')->label(__('admins.fields.reason'))->rows(2)->maxLength(500)])
            ->action(function (array $data, Model $record) use ($operation, $name): void {
                $reason = trim((string) ($data['reason'] ?? ''));
                self::perform(fn () => $operation(app(AdminTeam::class), self::target($record), $reason === '' ? null : $reason), __("admins.{$name}.done"));
            });
    }
}
