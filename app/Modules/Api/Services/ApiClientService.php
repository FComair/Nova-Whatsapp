<?php

declare(strict_types=1);

namespace App\Modules\Api\Services;

use App\Modules\Api\Models\ApiClient;
use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Émission et révocation des clients de l'API interne. Le secret n'est renvoyé qu'UNE fois, à la création.
 */
final class ApiClientService
{
    /**
     * @param  list<string>  $scopes
     * @return array{client: ApiClient, secret: string}
     */
    public function issue(Organization $organization, string $name, array $scopes, ?string $createdBy = null): array
    {
        $unknown = array_diff($scopes, ApiClient::SCOPES);

        if ($scopes === [] || $unknown !== []) {
            throw new InvalidArgumentException('Périmètres invalides : '.implode(', ', $unknown ?: ['aucun']));
        }

        $secret = Str::random(48);

        $client = ApiClient::query()->create([
            'org_id' => $organization->id,
            'name' => $name,
            'key_id' => 'nk_'.Str::lower(Str::random(24)),
            'secret_enc' => $secret,
            'scopes' => array_values(array_unique($scopes)),
            'created_by' => $createdBy,
        ]);

        Audit::record('api_client.created', $createdBy === null ? 'system' : 'user', $createdBy, $organization->id, 'api_clients', $client->id, ['scopes' => $client->scopes]);

        return ['client' => $client, 'secret' => $secret];
    }

    public function revoke(ApiClient $client, ?string $actorId = null): void
    {
        if ($client->revoked_at !== null) {
            return;
        }

        $client->forceFill(['revoked_at' => now()])->save();
        Audit::record('api_client.revoked', $actorId === null ? 'system' : 'user', $actorId, $client->org_id, 'api_clients', $client->id);
    }
}
