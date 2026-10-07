<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\Tenancy\Support\CurrentTenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écran affiché quand le compte est suspendu, en pause ou en suppression (flowcharts 4A, 4B, 4D).
 * Les actions (régularisation, réactivation, annulation de suppression) arrivent avec les étapes facturation et RGPD.
 */
final class AccountStateController
{
    public function __invoke(CurrentTenant $current): Response
    {
        $organization = $current->organization();

        return Inertia::render('AccountState', [
            'status' => $organization?->status,
            'reason' => $organization?->suspension_reason,
            'pauseUntil' => $organization?->pause_until?->toIso8601String(),
            'deletionScheduledFor' => $organization?->deletion_scheduled_for?->toIso8601String(),
        ]);
    }
}
