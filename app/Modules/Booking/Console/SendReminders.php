<?php

declare(strict_types=1);

namespace App\Modules\Booking\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Booking\Services\ReminderService;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Envoie les rappels de rendez-vous dus (planifié toutes les 5 minutes).
 */
final class SendReminders extends Command
{
    protected $signature = 'bookings:send-reminders';

    protected $description = 'Envoie les rappels WhatsApp des rendez-vous à venir';

    public function handle(TenantContext $tenants, ReminderService $reminders): int
    {
        $sent = 0;

        // Le rôle plateforme ne sert qu'à lister les organisations ; l'envoi se fait sous l'isolation de chacune.
        foreach (PlatformOrganization::query()->pluck('id') as $orgId) {
            $sent += (int) $tenants->run((string) $orgId, fn (): int => $reminders->sendDue(Organization::query()->findOrFail((string) $orgId)));
        }

        $this->info("{$sent} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
