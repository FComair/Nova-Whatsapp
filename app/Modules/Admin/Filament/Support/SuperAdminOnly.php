<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Admin\Support\SupportAccess;

/**
 * Écran réservé au Super Admin (docs/29 §5, docs/30 §7) : un agent de support (tickets seulement) et un éditeur de contenu (FAQ seulement)
 * ne le voient pas (menu masqué, adresse refusée).
 */
trait SuperAdminOnly
{
    public static function canAccess(): bool
    {
        return ! SupportAccess::isRestricted() && parent::canAccess();
    }
}
