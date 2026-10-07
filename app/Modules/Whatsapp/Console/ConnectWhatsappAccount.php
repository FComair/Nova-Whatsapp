<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use Illuminate\Console\Command;

/**
 * Rattache un numéro WhatsApp Business (identifiant Meta) à une organisation. Le jeton d'accès est saisi de façon masquée,
 * jamais en argument (il finirait dans l'historique du terminal). En production, à faire avec votre accord.
 */
final class ConnectWhatsappAccount extends Command
{
    protected $signature = 'whatsapp:connect {organization : Identifiant de l\'organisation} {phone_number_id : Identifiant du numéro chez Meta} {--display= : Numéro affiché}';

    protected $description = 'Rattache un numéro WhatsApp Business à une organisation';

    public function handle(TenantContext $tenants): int
    {
        $orgId = (string) $this->argument('organization');

        if (! PlatformOrganization::query()->whereKey($orgId)->exists()) {
            $this->error('Organisation introuvable.');

            return self::FAILURE;
        }

        $token = (string) $this->secret('Jeton d\'accès Meta');

        if ($token === '') {
            $this->error('Jeton vide.');

            return self::FAILURE;
        }

        $tenants->run($orgId, fn () => WhatsappAccount::query()->updateOrCreate(
            ['phone_number_id' => (string) $this->argument('phone_number_id')],
            ['org_id' => $orgId, 'display_phone' => $this->option('display'), 'access_token_enc' => $token, 'status' => 'active'],
        ));

        $this->info('Numéro rattaché.');

        return self::SUCCESS;
    }
}
