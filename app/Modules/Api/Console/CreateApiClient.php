<?php

declare(strict_types=1);

namespace App\Modules\Api\Console;

use App\Modules\Admin\Models\PlatformOrganization;
use App\Modules\Api\Models\ApiClient;
use App\Modules\Api\Services\ApiClientService;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Émet un client d'API pour une organisation (n8n, passerelle WhatsApp). Le secret n'est affiché qu'une fois.
 * À utiliser pour vos propres organisations ; une émission en production reste une décision du fondateur.
 */
final class CreateApiClient extends Command
{
    protected $signature = 'api-clients:create {organization : Identifiant de l\'organisation} {name : Nom du client (ex. n8n)} {--scope=* : Périmètres accordés}';

    protected $description = 'Émet une clé et un secret pour l\'API interne';

    public function handle(TenantContext $tenants, ApiClientService $service): int
    {
        $orgId = (string) $this->argument('organization');
        /** @var list<string> $scopes */
        $scopes = $this->option('scope') ?: ApiClient::SCOPES;

        if (! PlatformOrganization::query()->whereKey($orgId)->exists()) {
            $this->error('Organisation introuvable.');

            return self::FAILURE;
        }

        try {
            $issued = $tenants->run($orgId, fn (): array => $service->issue(Organization::query()->findOrFail($orgId), (string) $this->argument('name'), $scopes));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Client créé. Conservez le secret : il ne sera plus affiché.');
        $this->line('X-Nova-Key : '.$issued['client']->key_id);
        $this->line('Secret     : '.$issued['secret']);
        $this->line('Périmètres : '.implode(', ', $issued['client']->scopes));

        return self::SUCCESS;
    }
}
