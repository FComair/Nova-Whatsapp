<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Modules\Api\Http\Concerns\RespondsWithJson;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Whatsapp\Services\MessageSender;
use App\Modules\Whatsapp\Support\SendRefused;
use App\Modules\Whatsapp\Support\TransportFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Envoi d'une réponse WhatsApp par n8n (scope conversations:write). Les garde-fous du canal s'appliquent :
 * STOP respecté, fenêtre de service de 24 h.
 */
final class MessageApiController
{
    use RespondsWithJson;

    public function __construct(private readonly CurrentTenant $current, private readonly MessageSender $sender) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_phone' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'text' => ['required', 'string', 'max:4096'],
        ]);

        $customer = Customer::query()->where('phone_e164', $data['customer_phone'])->first();

        if ($customer === null) {
            return $this->error('customer_not_found', 'Client inconnu.', 404);
        }

        try {
            $message = $this->sender->sendText($this->current->organization() ?? abort(403), $customer, $data['text']);
        } catch (SendRefused $e) {
            return $this->error($e->reason, 'Envoi refusé.', 409);
        } catch (TransportFailed $e) {
            return $this->error('delivery_failed', 'WhatsApp a refusé le message.', 502, ['detail' => $e->errorCode]);
        }

        return new JsonResponse(['data' => ['id' => $message->id, 'status' => $message->status]], 201);
    }
}
