<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Support;

use App\Modules\Conversation\Contracts\LanguageModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Modèle de langage compatible « chat completions » (OpenAI, comme le flux n8n d'origine : gpt-4.1-mini).
 * Le texte des messages n'est jamais écrit dans les journaux.
 */
final class OpenAiModel implements LanguageModel
{
    public function available(): bool
    {
        return (string) config('conversation.ai.api_key') !== '';
    }

    public function complete(string $system, string $user): ?string
    {
        if (! $this->available()) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('conversation.ai.api_key'))
                ->baseUrl((string) config('conversation.ai.base_url'))
                ->timeout((int) config('conversation.ai.timeout_seconds'))
                ->post('/chat/completions', [
                    'model' => (string) config('conversation.ai.model'),
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
        } catch (Throwable) {
            Log::warning('Assistant WhatsApp : modèle de langage injoignable.');

            return null;
        }

        if ($response->failed()) {
            Log::warning('Assistant WhatsApp : le modèle de langage a refusé la demande (HTTP '.$response->status().').');

            return null;
        }

        $content = $response->json('choices.0.message.content');

        return is_string($content) && trim($content) !== '' ? trim($content) : null;
    }
}
