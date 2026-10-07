<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Http\Controllers;

use App\Modules\Whatsapp\Services\InboundProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook Meta Cloud API : vérification de l'URL (GET) et réception des notifications (POST, signées X-Hub-Signature-256).
 *
 * La signature porte sur le corps BRUT avec le secret de l'application Meta. Sans secret configuré, tout est refusé.
 */
final class WebhookController
{
    public function __construct(private readonly InboundProcessor $processor) {}

    public function verify(Request $request): Response
    {
        $token = config('whatsapp.verify_token');

        if (! is_string($token) || $token === '' || $request->query('hub_mode') !== 'subscribe' || ! hash_equals($token, (string) $request->query('hub_verify_token'))) {
            abort(403);
        }

        return new Response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request): JsonResponse
    {
        $secret = config('whatsapp.app_secret');
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! is_string($secret) || $secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature)) {
            abort(403);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ($payload['object'] ?? null) !== 'whatsapp_business_account') {
            return new JsonResponse(['status' => 'ignored']);
        }

        return new JsonResponse(['status' => 'ok', 'stored' => $this->processor->process($payload)]);
    }
}
