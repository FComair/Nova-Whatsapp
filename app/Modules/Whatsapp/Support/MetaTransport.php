<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Envoi par la Meta Cloud API (Graph API). Le jeton d'accès de l'établissement est lu chiffré en base.
 */
final class MetaTransport implements WhatsappTransport
{
    public function sendText(WhatsappAccount $account, string $toE164, string $text): string
    {
        return $this->post($account, [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($toE164, '+'),
            'type' => 'text',
            'text' => ['body' => $text, 'preview_url' => false],
        ]);
    }

    public function sendTemplate(WhatsappAccount $account, string $toE164, string $template, string $language, array $parameters): string
    {
        return $this->post($account, [
            'messaging_product' => 'whatsapp',
            'to' => ltrim($toE164, '+'),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
                'components' => [['type' => 'body', 'parameters' => array_map(fn (string $value): array => ['type' => 'text', 'text' => $value], $parameters)]],
            ],
        ]);
    }

    public function verifyNumber(WhatsappAccount $account): array
    {
        $json = $this->get($account, $account->phone_number_id, ['fields' => 'display_phone_number,verified_name']);

        return ['display_phone' => is_string($json['display_phone_number'] ?? null) ? $json['display_phone_number'] : null, 'verified_name' => is_string($json['verified_name'] ?? null) ? $json['verified_name'] : null];
    }

    public function createTemplate(WhatsappAccount $account, string $name, string $language, string $body, array $examples): TemplateInfo
    {
        $waba = $account->waba_id ?? throw new TransportFailed('no_waba');

        $payload = [
            'name' => $name,
            'language' => $language,
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => $body, 'example' => ['body_text' => [$examples]]]],
        ];

        $json = $this->request($account, 'post', "{$waba}/message_templates", $payload);

        return TemplateInfo::fromMeta($name, $language, (string) ($json['status'] ?? 'PENDING'), null, isset($json['id']) ? (string) $json['id'] : null);
    }

    public function listTemplates(WhatsappAccount $account): array
    {
        $waba = $account->waba_id ?? throw new TransportFailed('no_waba');

        $json = $this->get($account, "{$waba}/message_templates", ['fields' => 'name,language,status,rejected_reason', 'limit' => 200]);

        return collect(is_array($json['data'] ?? null) ? $json['data'] : [])
            ->map(fn (mixed $row): TemplateInfo => TemplateInfo::fromMeta((string) ($row['name'] ?? ''), (string) ($row['language'] ?? ''), (string) ($row['status'] ?? 'PENDING'), isset($row['rejected_reason']) && $row['rejected_reason'] !== 'NONE' ? (string) $row['rejected_reason'] : null, isset($row['id']) ? (string) $row['id'] : null))
            ->values()->all();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(WhatsappAccount $account, string $path, array $query = []): array
    {
        return $this->request($account, 'get', $path, $query);
    }

    /**
     * Appel de la Graph API ; seul le code d'erreur de Meta est conservé, jamais le contenu de la requête ni la réponse.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(WhatsappAccount $account, string $method, string $path, array $data = []): array
    {
        $url = rtrim((string) config('whatsapp.graph_url'), '/').'/'.config('whatsapp.graph_version').'/'.$path;

        try {
            $response = Http::withToken($account->access_token_enc)->timeout(10)->acceptJson()->{$method}($url, $data);
        } catch (ConnectionException) {
            throw new TransportFailed('network');
        }

        if ($response->failed()) {
            throw new TransportFailed('meta_'.(string) ($response->json('error.code') ?? $response->status()));
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(WhatsappAccount $account, array $payload): string
    {
        $url = rtrim((string) config('whatsapp.graph_url'), '/').'/'.config('whatsapp.graph_version').'/'.$account->phone_number_id.'/messages';

        try {
            $response = Http::withToken($account->access_token_enc)->timeout(10)->acceptJson()->post($url, $payload);
        } catch (ConnectionException) {
            throw new TransportFailed('network');
        }

        if ($response->failed()) {
            // Seul le code d'erreur de Meta est conservé : jamais le contenu de la requête.
            throw new TransportFailed('meta_'.(string) ($response->json('error.code') ?? $response->status()));
        }

        $id = $response->json('messages.0.id');

        return is_string($id) ? $id : throw new TransportFailed('meta_no_id');
    }
}
