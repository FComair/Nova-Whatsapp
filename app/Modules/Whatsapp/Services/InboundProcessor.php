<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Services;

use App\Modules\Audit\Audit;
use App\Modules\Conversation\Jobs\HandleInboundMessage;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\ConsentRecorder;
use App\Modules\Customers\Services\CustomerDirectory;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Jobs\ForwardInboundMessage;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use App\Modules\Whatsapp\Support\SendRefused;
use App\Modules\Whatsapp\Support\TransportFailed;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Traite une notification Meta (messages entrants et accusés d'état).
 *
 * - L'établissement est retrouvé par l'identifiant du numéro (fonction SECURITY DEFINER), puis tout se passe sous son contexte (RLS).
 * - Un même message reçu deux fois n'est traité qu'une fois (identifiant WhatsApp unique).
 * - STOP / START : désabonnement et réabonnement, tracés ; le message de confirmation part dans la langue du client.
 * - Un client désabonné qui écrit sans dire START ne déclenche aucune réponse automatique.
 */
final class InboundProcessor
{
    /** Ordre des états d'un message sortant : un état ne recule jamais (les notifications arrivent parfois dans le désordre). */
    private const STATUS_RANK = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly CustomerDirectory $customers,
        private readonly MessageSender $sender,
        private readonly ConsentRecorder $consents,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return int nombre de messages entrants enregistrés
     */
    public function process(array $payload): int
    {
        $stored = 0;

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');
                $account = $phoneNumberId === '' ? null : DB::selectOne('select * from whatsapp_account_lookup(?)', [$phoneNumberId]);

                // Numéro inconnu ou désactivé : on accuse réception (200) sans rien enregistrer.
                if ($account === null || $account->status !== 'active') {
                    continue;
                }

                $stored += (int) $this->tenants->run((string) $account->org_id, fn (): int => $this->handleChange((string) $account->org_id, $value));
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function handleChange(string $orgId, array $value): int
    {
        foreach ((array) ($value['statuses'] ?? []) as $status) {
            $this->applyStatus((array) $status);
        }

        $organization = Organization::query()->findOrFail($orgId);
        $names = collect((array) ($value['contacts'] ?? []))->mapWithKeys(fn ($contact): array => [(string) ($contact['wa_id'] ?? '') => $contact['profile']['name'] ?? null]);
        $stored = 0;

        foreach ((array) ($value['messages'] ?? []) as $raw) {
            $stored += $this->handleMessage($organization, (array) $raw, $names->all()) ? 1 : 0;
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, string|null>  $names
     */
    private function handleMessage(Organization $organization, array $raw, array $names): bool
    {
        $waId = (string) ($raw['id'] ?? '');
        $from = (string) ($raw['from'] ?? '');

        if ($waId === '' || preg_match('/^[1-9]\d{7,14}$/', $from) !== 1) {
            return false;
        }

        if (WhatsappMessage::query()->where('wa_message_id', $waId)->exists()) {
            return false;
        }

        $type = Str::limit((string) ($raw['type'] ?? 'text'), 20, '');
        $text = $type === 'text' ? Str::limit((string) ($raw['text']['body'] ?? ''), 4096, '') : null;
        $customer = $this->customers->findOrCreate($organization, '+'.$from, isset($names[$from]) ? (string) $names[$from] : null);

        try {
            $message = WhatsappMessage::query()->create([
                'org_id' => $organization->id,
                'customer_id' => $customer->id,
                'direction' => 'in',
                'wa_message_id' => $waId,
                'type' => $type,
                'body_enc' => $text,
                'status' => 'received',
            ]);
        } catch (UniqueConstraintViolationException) {
            return false; // deux notifications identiques simultanées
        }

        $keyword = $text === null ? null : $this->keyword($text);

        if ($keyword === 'opt_out' || $keyword === 'opt_in') {
            $this->changeConsent($organization, $customer, $keyword === 'opt_out');

            return true;
        }

        // Client désabonné : aucune réponse automatique tant qu'il n'a pas dit START.
        if ($customer->whatsapp_opted_out_at === null) {
            // Pilote de conversation : « n8n » (défaut) transmet à n8n ; « native » fait répondre l'assistant intégré à Nova.
            if (config('conversation.driver') === 'native') {
                HandleInboundMessage::dispatch($organization->id, $message->id);
            } else {
                ForwardInboundMessage::dispatch($organization->id, $message->id);
            }
        }

        return true;
    }

    private function changeConsent(Organization $organization, Customer $customer, bool $optOut): void
    {
        $customer->forceFill(['whatsapp_opted_out_at' => $optOut ? now() : null])->save();
        $optOut ? $this->consents->recordStop($customer) : $this->consents->recordStart($customer);
        Audit::record($optOut ? 'customer.whatsapp_opt_out' : 'customer.whatsapp_opt_in', 'system', null, $organization->id, 'customers', $customer->id);

        $key = $optOut ? 'whatsapp.opt_out_confirmed' : 'whatsapp.opt_in_confirmed';
        $locale = in_array($customer->language, (array) config('nova.locales'), true) ? $customer->language : (string) config('app.fallback_locale');

        try {
            $this->sender->sendText($organization, $customer, App::currentLocale() === $locale ? __($key) : __($key, [], $locale), ignoreOptOut: true);
        } catch (SendRefused|TransportFailed) {
            // La confirmation est un plus : le consentement est déjà enregistré.
        }
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(array $status): void
    {
        $waId = (string) ($status['id'] ?? '');
        $new = (string) ($status['status'] ?? '');
        $message = $waId === '' ? null : WhatsappMessage::query()->where('wa_message_id', $waId)->where('direction', 'out')->first();

        if ($message === null) {
            return;
        }

        if ($new === 'failed') {
            $message->forceFill(['status' => 'failed', 'error_code' => 'meta_'.(string) ($status['errors'][0]['code'] ?? 'unknown')])->save();

            return;
        }

        if (isset(self::STATUS_RANK[$new]) && self::STATUS_RANK[$new] > (self::STATUS_RANK[$message->status] ?? -1)) {
            $message->forceFill(['status' => $new])->save();
        }
    }

    /** @return 'opt_out'|'opt_in'|null */
    private function keyword(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text, " \t\n\r\0\x0B.!?"));

        return match (true) {
            in_array($normalized, (array) config('whatsapp.opt_out_keywords'), true) => 'opt_out',
            in_array($normalized, (array) config('whatsapp.opt_in_keywords'), true) => 'opt_in',
            default => null,
        };
    }
}
