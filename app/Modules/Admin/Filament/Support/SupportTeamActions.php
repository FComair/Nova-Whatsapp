<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Admin\Exceptions\TeamRefused;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\SupportTeam;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Admin\Support\SupportPermissions;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Actions de l'équipe de support (docs/32 §2), partagées par la liste et la fiche d'un agent. Chaque action passe par `SupportTeam`
 * (qui contrôle le droit, le motif et l'audit) : un refus devient un message, jamais une erreur serveur.
 */
final class SupportTeamActions
{
    public static function admin(): PlatformAdmin
    {
        $admin = SupportAccess::admin();

        return $admin !== null && $admin->support_role === null ? $admin : abort(403);
    }

    public static function agent(Model $record): PlatformAdmin
    {
        return $record instanceof PlatformAdmin ? $record : abort(404);
    }

    public static function perform(callable $operation, string $success): bool
    {
        try {
            $operation();
        } catch (TeamRefused $e) {
            Notification::make()->danger()->title(__('team.errors.title'))->body($e->message())->send();

            return false;
        }

        Notification::make()->success()->title($success)->send();

        return true;
    }

    /**
     * @return array<int, mixed>
     */
    public static function profileFields(bool $withEmail): array
    {
        $fields = [
            TextInput::make('name')->label(__('team.fields.name'))->required()->maxLength(120)->autocomplete('off'),
        ];

        if ($withEmail) {
            $fields[] = TextInput::make('email')->label(__('team.fields.email'))->email()->required()->maxLength(190)->autocomplete('off')->helperText(__('team.fields.email_help'));
        }

        return [
            ...$fields,
            TextInput::make('phone')->label(__('team.fields.phone'))->tel()->maxLength(30),
            CheckboxList::make('permissions')->label(__('team.fields.permissions'))->helperText(__('team.fields.permissions_help'))->columns(1)
                ->options(collect(SupportPermissions::DEFAULTS)->mapWithKeys(fn (bool $default, string $permission): array => [$permission => __("team.permissions.{$permission}")])->all())
                ->descriptions(collect(SupportPermissions::DEFAULTS)->mapWithKeys(fn (bool $default, string $permission): array => [$permission => __("team.permissions_help.{$permission}")])->all()),
            Select::make('scope')->label(__('team.fields.scope'))->helperText(__('team.fields.scope_help'))->multiple()->searchable()->native(false)
                ->getSearchResultsUsing(fn (string $search): array => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->whereNull('deleted_at')->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%')->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                ->getOptionLabelsUsing(fn (array $values): array => DB::connection(PlatformAudit::CONNECTION)->table('organizations')->whereIn('id', $values)->pluck('name', 'id')->all()),
            Textarea::make('internal_notes')->label(__('team.fields.notes'))->helperText(__('team.fields.notes_help'))->rows(3)->maxLength(2000),
        ];
    }

    public static function create(): Action
    {
        return Action::make('create')->label(__('team.actions.create'))->icon('heroicon-m-user-plus')
            ->modalHeading(__('team.actions.create'))->modalDescription(__('team.create.description'))->modalSubmitActionLabel(__('team.create.submit'))
            ->fillForm(fn (): array => ['permissions' => array_keys(array_filter(SupportPermissions::DEFAULTS))])
            ->schema(self::profileFields(true))
            ->action(function (array $data): void {
                self::perform(fn () => app(SupportTeam::class)->create(self::admin(), [
                    'name' => (string) $data['name'], 'email' => (string) $data['email'], 'phone' => $data['phone'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null,
                    'permissions' => self::permissionsFrom($data), 'scope' => (array) ($data['scope'] ?? []),
                ]), __('team.create.done'));
            });
    }

    public static function edit(): Action
    {
        return Action::make('edit')->label(__('team.actions.edit'))->icon('heroicon-m-pencil-square')->modalHeading(__('team.actions.edit'))
            ->visible(fn (Model $record): bool => self::agent($record)->status !== PlatformAdmin::STATUS_REMOVED)
            ->fillForm(function (Model $record): array {
                $agent = self::agent($record);

                return [
                    'name' => $agent->name, 'phone' => $agent->phone, 'internal_notes' => $agent->internal_notes,
                    'permissions' => array_keys(array_filter(SupportPermissions::normalise($agent->permissions ?? []))),
                    'scope' => app(SupportTeam::class)->scopeOf($agent),
                ];
            })
            ->schema(self::profileFields(false))
            ->action(function (array $data, Model $record): void {
                self::perform(fn () => app(SupportTeam::class)->update(self::admin(), self::agent($record), [
                    'name' => (string) $data['name'], 'phone' => $data['phone'] ?? null, 'internal_notes' => $data['internal_notes'] ?? null,
                    'permissions' => self::permissionsFrom($data), 'scope' => (array) ($data['scope'] ?? []),
                ]), __('team.saved'));
            });
    }

    public static function resendInvitation(): Action
    {
        return Action::make('resend_invitation')->label(__('team.actions.resend'))->icon('heroicon-m-paper-airplane')
            ->visible(fn (Model $record): bool => self::agent($record)->status === PlatformAdmin::STATUS_INVITED)
            ->requiresConfirmation()->modalHeading(__('team.actions.resend'))->modalDescription(__('team.resend.description'))
            ->action(fn (Model $record) => self::perform(fn () => app(SupportTeam::class)->resendInvitation(self::admin(), self::agent($record)), __('team.resend.done')));
    }

    public static function resetPassword(): Action
    {
        return Action::make('reset_password')->label(__('team.actions.reset_password'))->icon('heroicon-m-key')
            ->visible(fn (Model $record): bool => self::agent($record)->status === PlatformAdmin::STATUS_ACTIVE)
            ->requiresConfirmation()->modalHeading(__('team.actions.reset_password'))->modalDescription(__('team.reset.description'))
            ->action(fn (Model $record) => self::perform(fn () => app(SupportTeam::class)->sendPasswordReset(self::admin(), self::agent($record)), __('team.reset.done')));
    }

    public static function suspend(): Action
    {
        return self::offboarding('suspend', 'heroicon-m-pause-circle', [PlatformAdmin::STATUS_ACTIVE], fn (SupportTeam $team, PlatformAdmin $agent, ?string $to, ?string $reason) => $team->suspend(self::admin(), $agent, $to, $reason));
    }

    public static function remove(): Action
    {
        return self::offboarding('remove', 'heroicon-m-user-minus', [PlatformAdmin::STATUS_ACTIVE, PlatformAdmin::STATUS_INVITED, PlatformAdmin::STATUS_SUSPENDED], fn (SupportTeam $team, PlatformAdmin $agent, ?string $to, ?string $reason) => $team->remove(self::admin(), $agent, $to, $reason));
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')->label(__('team.actions.reactivate'))->icon('heroicon-m-play-circle')->color('success')
            ->visible(fn (Model $record): bool => in_array(self::agent($record)->status, [PlatformAdmin::STATUS_SUSPENDED, PlatformAdmin::STATUS_REMOVED], true))
            ->requiresConfirmation()->modalHeading(__('team.actions.reactivate'))->modalDescription(__('team.reactivate.description'))
            ->action(fn (Model $record) => self::perform(fn () => app(SupportTeam::class)->reactivate(self::admin(), self::agent($record)), __('team.reactivate.done')));
    }

    /**
     * Suspension et retrait : choisir où vont les tickets ouverts de l'agent (obligatoire s'il en a), motif facultatif.
     *
     * @param  list<string>  $statuses
     * @param  callable(SupportTeam, PlatformAdmin, ?string, ?string): mixed  $operation
     */
    private static function offboarding(string $name, string $icon, array $statuses, callable $operation): Action
    {
        return Action::make($name)->label(__("team.actions.{$name}"))->icon($icon)->color('danger')
            ->visible(fn (Model $record): bool => in_array(self::agent($record)->status, $statuses, true))
            ->modalHeading(__("team.{$name}.heading"))->modalDescription(fn (Model $record): string => __("team.{$name}.description", ['count' => app(SupportTeam::class)->openTicketCount(self::agent($record))]))
            ->modalIcon('heroicon-o-exclamation-triangle')->modalSubmitActionLabel(__("team.actions.{$name}"))
            ->schema(fn (Model $record): array => [
                Select::make('reassign')->label(__('team.reassign.label'))->helperText(__('team.reassign.help'))->native(false)
                    ->visible(app(SupportTeam::class)->openTicketCount(self::agent($record)) > 0)->required(app(SupportTeam::class)->openTicketCount(self::agent($record)) > 0)
                    ->options(fn (): array => [SupportTeam::QUEUE => __('team.reassign.queue'), ...array_diff_key(SupportAccess::assignees(), [self::agent($record)->id => true])]),
                Textarea::make('reason')->label(__('team.fields.reason'))->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, Model $record) use ($operation): void {
                $reason = trim((string) ($data['reason'] ?? ''));
                self::perform(fn () => $operation(app(SupportTeam::class), self::agent($record), $data['reassign'] ?? null, $reason === '' ? null : $reason), __('team.saved'));
            });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool>
     */
    private static function permissionsFrom(array $data): array
    {
        $checked = (array) ($data['permissions'] ?? []);

        return collect(array_keys(SupportPermissions::DEFAULTS))->mapWithKeys(fn (string $permission): array => [$permission => in_array($permission, $checked, true)])->all();
    }
}
