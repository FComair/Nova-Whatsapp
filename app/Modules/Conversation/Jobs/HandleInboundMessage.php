<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Jobs;

use App\Modules\Conversation\Services\ConversationEngine;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Whatsapp\Models\WhatsappMessage;
use App\Modules\Whatsapp\Services\MessageSender;
use App\Modules\Whatsapp\Support\SendRefused;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fait répondre l'assistant natif à un message entrant (pilote « native », voir config/conversation.php).
 *
 * Seuls les identifiants sont placés dans la file ; le texte est relu en base sous le contexte de l'établissement.
 * Un message qui n'est pas du texte (image, vocal…) reçoit le menu de clarification. En cas d'échec d'envoi, le job est rejoué :
 * le moteur ne fait pas avancer deux fois le dialogue (même identifiant de message) et ne renvoie que ce qui n'est pas parti.
 */
final class HandleInboundMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $orgId, public readonly string $messageId) {}

    public function handle(TenantContext $tenants, ConversationEngine $engine, MessageSender $sender): void
    {
        $tenants->run($this->orgId, function () use ($engine, $sender): void {
            $message = WhatsappMessage::query()->find($this->messageId);
            $customer = $message === null ? null : Customer::query()->find($message->customer_id);
            $organization = Organization::query()->find($this->orgId);

            if ($message === null || $customer === null || $organization === null) {
                return;
            }

            $text = $message->type === 'text' ? (string) $message->body_enc : '';
            $replies = $engine->respond($organization, $customer, $text, $message->id);

            foreach ($replies as $reply) {
                try {
                    $sender->sendText($organization, $customer, $reply);
                } catch (SendRefused) {
                    $engine->acknowledge($organization, $customer, count($replies)); // fenêtre fermée / désabonné : inutile de réessayer

                    return;
                }

                $engine->acknowledge($organization, $customer); // une panne de transport (TransportFailed) remonte : le job est rejoué
            }
        });
    }
}
