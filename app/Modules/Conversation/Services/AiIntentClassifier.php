<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Services;

use App\Modules\Conversation\Contracts\LanguageModel;

/**
 * Compréhension de secours : quand les règles ne trouvent rien, le modèle de langage classe l'intention (nœud AI_DETECT_INTENT de n8n).
 *
 * Le modèle répond en JSON {intent, confidence}. Une intention inconnue, un JSON illisible, ou une confiance sous le seuil
 * (0,70 par défaut) donnent « unclear » : l'assistant propose alors le menu de clarification. Sans clé configurée, même résultat.
 */
final class AiIntentClassifier
{
    private const ALLOWED = ['booking', 'modify_booking', 'cancel_booking', 'faq', 'human_handoff', 'greeting', 'unclear'];

    public function __construct(private readonly LanguageModel $model) {}

    /**
     * @return string une intention de IntentDetector, ou `unclear`
     */
    public function classify(string $message, string $state, ?string $intent): string
    {
        if (! $this->model->available()) {
            return 'unclear';
        }

        $answer = $this->model->complete(
            (string) trans('conversation.prompts.intent', [], 'fr'),
            (string) trans('conversation.prompts.intent_user', ['message' => $message, 'state' => $state, 'intent' => $intent ?? 'none'], 'fr'),
        );

        if ($answer === null) {
            return 'unclear';
        }

        $clean = trim((string) preg_replace('/```(?:json)?/i', '', $answer));
        $data = json_decode($clean, true);

        if (! is_array($data)) {
            return 'unclear';
        }

        $intent = strtolower(trim((string) ($data['intent'] ?? 'unclear')));
        $confidence = is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0;

        if (! in_array($intent, self::ALLOWED, true) || $confidence < 0 || $confidence > 1) {
            return 'unclear';
        }

        return $intent !== 'unclear' && $confidence < (float) config('conversation.ai.min_confidence') ? 'unclear' : $intent;
    }
}
