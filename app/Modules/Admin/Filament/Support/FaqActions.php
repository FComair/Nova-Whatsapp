<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Help\Support\FaqRefused;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Exécution commune des actions sur les FAQ et les catégories (docs/30) : droit d'accès, messages de réussite et de refus traduits.
 */
final class FaqActions
{
    /** Le compte connecté, s'il a le droit de gérer les FAQ (Super Admin ou éditeur de contenu). */
    public static function admin(): PlatformAdmin
    {
        $admin = SupportAccess::admin();

        abort_unless($admin !== null && SupportAccess::canManageFaq($admin), 403);

        return $admin;
    }

    /**
     * Lance une action ; renvoie vrai si elle a abouti. Un refus de règle (langue manquante, adresse prise, catégorie non vide…) devient
     * une notification claire, jamais une erreur.
     *
     * @param  callable(PlatformAdmin): mixed  $operation
     */
    public static function perform(callable $operation, ?string $success = null): bool
    {
        try {
            $operation(self::admin());
        } catch (FaqRefused $refused) {
            Notification::make()->danger()->title(__('faq.errors.title'))->body(__("faq.errors.{$refused->reason}", $refused->params))->persistent()->send();

            return false;
        } catch (AuthorizationException) {
            Notification::make()->danger()->title(__('admins.errors.title'))->body(__('admins.errors.forbidden'))->send();

            return false;
        }

        Notification::make()->success()->title($success ?? __('faq.saved'))->send();

        return true;
    }

    /**
     * Résultat d'une action en lot : « N mis à jour » et, le cas échéant, « N ignorés ».
     *
     * @param  array{done: int, skipped: int}  $result
     */
    public static function bulkResult(array $result): void
    {
        $notification = Notification::make()->title(__('faq.bulk.done', ['count' => $result['done']]));
        $result['skipped'] > 0 ? $notification->warning()->body(__('faq.bulk.skipped', ['count' => $result['skipped']])) : $notification->success();
        $notification->send();
    }
}
