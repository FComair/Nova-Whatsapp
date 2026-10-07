<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Admin\Services\SensitiveActionGuard;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;

/**
 * Champs communs à toute action sensible du Super Admin (docs/02 §3.3) : motif facultatif. Plus de mot de passe,
 * de code 2FA ni de nom d'organisation à saisir par action (décisions du fondateur).
 */
final class SensitiveActionFields
{
    /**
     * @return list<Checkbox|Textarea>
     */
    public static function make(bool $irreversible = false): array
    {
        $fields = [
            Textarea::make('reason')->label(__('admin.sensitive.reason'))->helperText(__('admin.sensitive.reason_help'))->rows(3)->maxLength(500),
        ];

        if ($irreversible) {
            $fields[] = Checkbox::make('irreversible')->label(__('admin.sensitive.irreversible'))->accepted()->required();
        }

        return $fields;
    }

    /**
     * Identifie l'administrateur connecté et normalise le motif (facultatif : `null` s'il est vide).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string|null} identifiant de l'administrateur et motif
     */
    public static function authorize(Action $action, array $data): array
    {
        $admin = Filament::auth()->user() ?? abort(403);

        return [$admin->getAuthIdentifier(), app(SensitiveActionGuard::class)->authorize($admin, (string) ($data['reason'] ?? ''))];
    }
}
