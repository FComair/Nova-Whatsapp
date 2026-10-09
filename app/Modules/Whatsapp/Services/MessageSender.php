<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Services;

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Whatsapp\Contracts\WhatsappTransport;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use App\Modules\Whatsapp\Support\SendRefused;
use App\Modules\Whatsapp\Support\TransportFailed;
use Carbon\CarbonImmutable;

/**
 * Envoi d'un message WhatsApp à un client final, avec les garde-fous du canal :
 * - jamais après un STOP (sauf l'accusé de réception du STOP lui-même) ;
 * - réponse libre uniquement dans la fenêtre de service de 24 h qui suit le dernier message du client.
 *
 * Chaque envoi est journalisé (texte chiffré). Échec de Meta : le message reste consigné en `failed`, sans le texte dans l'erreur.
 */
final class MessageSender
{
    public function __construct(private readonly WhatsappTransport $transport) {}

    /**
     * @throws SendRefused
     * @throws TransportFailed
     */
    public function sendText(Organization $organization, Customer $customer, string $text, bool $ignoreOptOut = false): WhatsappMessage
    {
        $account = $this->account($organization);

        if ($customer->whatsapp_opted_out_at !== null && ! $ignoreOptOut) {
            throw new SendRefused(SendRefused::OPTED_OUT);
        }

        if (! $this->windowOpen($customer)) {
            throw new SendRefused(SendRefused::WINDOW_CLOSED);
        }

        return $this->deliver($organization, $customer, 'text', $text, WhatsappUsage::SERVICE, fn (): string => $this->transport->sendText($account, $customer->phone_e164, $text));
    }

    /**
     * Message modèle (rappels, confirmations hors fenêtre de service). Jamais après un STOP ; pas de fenêtre de 24 h à respecter.
     *
     * @param  list<string>  $parameters
     *
     * @throws SendRefused
     * @throws TransportFailed
     */
    public function sendTemplate(Organization $organization, Customer $customer, string $template, string $language, array $parameters, string $category = WhatsappUsage::UTILITY): WhatsappMessage
    {
        $account = $this->account($organization);

        if ($customer->whatsapp_opted_out_at !== null) {
            throw new SendRefused(SendRefused::OPTED_OUT);
        }

        return $this->deliver(
            $organization,
            $customer,
            'template',
            json_encode(['template' => $template, 'language' => $language, 'parameters' => $parameters], JSON_THROW_ON_ERROR),
            $category,
            fn (): string => $this->transport->sendTemplate($account, $customer->phone_e164, $template, $language, $parameters),
        );
    }

    private function account(Organization $organization): WhatsappAccount
    {
        return WhatsappAccount::query()->where('org_id', $organization->id)->where('status', 'active')->orderBy('created_at')->first()
            ?? throw new SendRefused(SendRefused::NO_ACCOUNT);
    }

    /**
     * @param  callable(): string  $send
     */
    private function deliver(Organization $organization, Customer $customer, string $type, string $body, string $category, callable $send): WhatsappMessage
    {
        $message = WhatsappMessage::query()->create([
            'org_id' => $organization->id,
            'customer_id' => $customer->id,
            'direction' => 'out',
            'type' => $type,
            'body_enc' => $body,
            'status' => 'queued',
        ]);

        try {
            $waId = $send();
        } catch (TransportFailed $e) {
            $message->forceFill(['status' => 'failed', 'error_code' => $e->errorCode])->save();

            throw $e;
        }

        $message->forceFill(['status' => 'sent', 'wa_message_id' => $waId])->save();
        WhatsappUsage::record($organization->id, $category, $customer->phone_e164);

        return $message;
    }

    /** La fenêtre de service est ouverte si le client a écrit il y a moins de 24 h. */
    public function windowOpen(Customer $customer): bool
    {
        $last = WhatsappMessage::query()->where('customer_id', $customer->id)->where('direction', 'in')->max('created_at');

        return $last !== null && CarbonImmutable::parse($last)->greaterThan(CarbonImmutable::now()->subHours((int) config('whatsapp.service_window_hours')));
    }
}
