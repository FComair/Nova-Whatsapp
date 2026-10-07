<?php

declare(strict_types=1);

namespace App\Modules\Booking\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Booking\Services\BookingCore;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Libère les créneaux bloqués en attente de confirmation dont le délai est dépassé (planifié chaque minute).
 */
final class ReleaseExpiredHolds extends Command
{
    protected $signature = 'bookings:release-holds';

    protected $description = 'Libère les créneaux en attente de confirmation dont le blocage a expiré';

    public function handle(TenantContext $tenants, BookingCore $core): int
    {
        $released = 0;

        // Le rôle plateforme ne sert qu'à lister les organisations ; chaque libération se fait sous l'isolation du client.
        foreach (PlatformOrganization::query()->pluck('id') as $orgId) {
            $released += (int) $tenants->run((string) $orgId, fn (): int => $core->releaseExpiredHolds());
        }

        $this->info("{$released} créneau(x) libéré(s).");

        return self::SUCCESS;
    }
}
