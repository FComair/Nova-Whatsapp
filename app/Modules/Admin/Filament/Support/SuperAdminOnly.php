<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Admin\Support\AdminAccess;
use App\Modules\Admin\Support\SupportAccess;

/**
 * Écran réservé aux administrateurs (docs/29 §5, docs/30 §7, docs/38) : un agent de support (tickets seulement) et un éditeur de contenu (FAQ
 * seulement) ne le voient pas (menu masqué, adresse refusée) ; un administrateur ne le voit que si son rôle lui en donne la permission de lecture
 * (`$viewPermission` de l'écran). Sans cette propriété, tout administrateur le voit.
 */
trait SuperAdminOnly
{
    public static function canAccess(): bool
    {
        $permission = property_exists(static::class, 'viewPermission') ? static::$viewPermission : null;

        return ! SupportAccess::isRestricted() && ($permission === null || AdminAccess::can($permission)) && parent::canAccess();
    }
}
