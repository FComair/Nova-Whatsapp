<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Support;

use App\Modules\Admin\Support\SupportAccess;

/**
 * Un agent de support (docs/29 §5) n'a accès qu'aux tickets : cet écran lui est fermé (menu masqué, adresse refusée). Le Super Admin
 * garde l'accès habituel.
 */
trait HiddenFromSupportAgents
{
    public static function canAccess(): bool
    {
        return ! SupportAccess::isAgent() && parent::canAccess();
    }
}
