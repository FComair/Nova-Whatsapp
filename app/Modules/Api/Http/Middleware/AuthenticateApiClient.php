<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Middleware;

use App\Modules\Api\Models\ApiClient;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie un client de l'API interne (n8n, passerelle WhatsApp) : clé publique + signature HMAC-SHA256.
 *
 * En-têtes : X-Nova-Key, X-Nova-Timestamp (secondes Unix, tolérance de 5 min) et X-Nova-Signature.
 * Signature = hex(HMAC-SHA256(secret, "{timestamp}\n{MÉTHODE}\n{chemin et requête}\n{sha256 du corps}")).
 *
 * Usage : `api.auth:bookings:write` (un ou plusieurs périmètres exigés). Le contexte d'organisation est posé pour la durée
 * de la requête puis vidé : la RLS s'applique exactement comme pour le dashboard.
 */
final class AuthenticateApiClient
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly TenantContext $tenant, private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $keyId = (string) $request->header('X-Nova-Key', '');
        $timestamp = (string) $request->header('X-Nova-Timestamp', '');
        $signature = strtolower((string) $request->header('X-Nova-Signature', ''));

        $row = $keyId === '' ? null : DB::selectOne('select * from api_client_lookup(?)', [$keyId]);
        $secret = $row === null ? str_repeat('0', 48) : $this->secretOf($row->secret_enc);

        // Même travail et même réponse que la clé soit inconnue, révoquée ou la signature fausse : aucun oracle.
        $valid = hash_equals($this->sign($secret, $timestamp, $request), $signature);

        if ($row === null || $row->revoked_at !== null || ! $valid) {
            return $this->error('invalid_credentials', 'Clé ou signature invalide.', 401);
        }

        if (! ctype_digit($timestamp) || abs(CarbonImmutable::now()->getTimestamp() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return $this->error('timestamp_out_of_range', 'Horodatage hors de la tolérance de 5 minutes.', 401);
        }

        $granted = json_decode((string) $row->scopes, true) ?: [];

        if (array_diff($scopes, $granted) !== []) {
            return $this->error('insufficient_scope', 'Périmètre insuffisant pour cette opération.', 403);
        }

        $this->tenant->set((string) $row->org_id);

        try {
            $organization = Organization::query()->find($row->org_id);

            if ($organization === null || in_array($organization->status, [Organization::STATUS_DELETED, Organization::STATUS_PAUSED, Organization::STATUS_PENDING_DELETION], true)) {
                // Pause : le message d'absence du propriétaire (dans sa langue) est fourni au bot pour répondre aux clients (flowchart 4B).
                $absence = $organization?->status === Organization::STATUS_PAUSED ? ($organization->unavailable_message[$organization->locale] ?? null) : null;

                return $this->error('account_unavailable', 'Le compte n\'est pas disponible.', 503, $absence === null ? [] : ['unavailable_message' => $absence, 'locale' => $organization->locale]);
            }

            // Compte suspendu : lecture seule, comme le dashboard.
            if ($organization->status === Organization::STATUS_SUSPENDED && ! $request->isMethodSafe()) {
                return $this->error('read_only', 'Le compte est en lecture seule.', 403);
            }

            $this->current->set($organization, 'api');

            $client = ApiClient::query()->find($row->id);
            $request->attributes->set('api_client', $client);

            if ($client !== null && ($client->last_used_at === null || $client->last_used_at < CarbonImmutable::now()->subMinute())) {
                $client->forceFill(['last_used_at' => now()])->saveQuietly();
            }

            return $next($request);
        } finally {
            $this->tenant->clear();
            $this->current->clear();
        }
    }

    /** Signature attendue pour une requête : exposée pour les clients de test et la documentation. */
    public function sign(string $secret, string $timestamp, Request $request): string
    {
        $canonical = implode("\n", [$timestamp, strtoupper($request->method()), $request->getRequestUri(), hash('sha256', $request->getContent())]);

        return hash_hmac('sha256', $canonical, $secret);
    }

    private function secretOf(string $encrypted): string
    {
        return (string) app('encrypter')->decryptString($encrypted);
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message, ...$extra]], $status);
    }
}
