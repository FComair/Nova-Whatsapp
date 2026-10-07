<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Jobs;

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Transmet un message entrant à n8n, signé (HMAC-SHA256 de « horodatage.corps »). n8n répond ensuite via l'API interne.
 *
 * Seuls les identifiants sont placés dans la file : le texte est relu en base, sous le contexte de l'établissement,
 * au moment de l'envoi (il ne transite pas dans Redis).
 */
final class ForwardInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public readonly string $orgId, public readonly string $messageId) {}

    public function handle(TenantContext $tenants): void
    {
        $url = config('whatsapp.n8n.url');
        $secret = (string) config('whatsapp.n8n.signing_secret');

        if (! is_string($url) || $url === '' || $secret === '') {
            return;
        }

        $body = $tenants->run($this->orgId, function (): ?string {
            $message = WhatsappMessage::query()->find($this->messageId);
            $customer = $message === null ? null : Customer::query()->find($message->customer_id);

            if ($message === null || $customer === null) {
                return null;
            }

            return json_encode([
                'organization_id' => $this->orgId,
                'customer' => ['id' => $customer->id, 'phone' => $customer->phone_e164, 'name' => $customer->name, 'language' => $customer->language],
                'message' => ['id' => $message->id, 'type' => $message->type, 'text' => $message->body_enc, 'received_at' => $message->created_at->utc()->toIso8601String()],
            ], JSON_THROW_ON_ERROR);
        });

        if ($body === null) {
            return;
        }

        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $response = Http::withHeaders([
            'X-Nova-Timestamp' => $timestamp,
            'X-Nova-Signature' => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ])->timeout((int) config('whatsapp.n8n.timeout_seconds'))->withBody($body, 'application/json')->post($url);

        if ($response->failed()) {
            throw new RuntimeException('Transfert vers n8n échoué (HTTP '.$response->status().').');
        }
    }
}
