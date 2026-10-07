<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Services;

use App\Modules\Conversation\Support\Keywords;
use App\Modules\Conversation\Support\Text;

/**
 * Comprend l'intention d'un message libre par règles (mots-clés), comme le nœud DETECT_INTENT du flux n8n.
 * Quand aucune règle ne tranche, le résultat est `unknown` et le modèle de langage prend le relais (AiIntentClassifier).
 *
 * Ordre des règles (identique à n8n) : parler à quelqu'un, annuler, modifier, réserver, question, salutation.
 */
final class IntentDetector
{
    public const BOOKING = 'booking';

    public const MODIFY = 'modify_booking';

    public const CANCEL = 'cancel_booking';

    public const FAQ = 'faq';

    public const HANDOFF = 'human_handoff';

    public const GREETING = 'greeting';

    public const UNKNOWN = 'unknown';

    public const CLARIFY = 'clarify_intent';

    public function detect(string $raw): string
    {
        $message = Text::normalize($raw);

        return match (true) {
            Keywords::contains($message, 'human') => self::HANDOFF,
            Keywords::contains($message, 'cancel') => self::CANCEL,
            Keywords::contains($message, 'modify') => self::MODIFY,
            Keywords::contains($message, 'book') || Keywords::contains($message, 'book_extra') => self::BOOKING,
            $this->isQuestion($raw) => self::FAQ,
            Keywords::equals($message, 'greeting') => self::GREETING,
            default => self::UNKNOWN,
        };
    }

    /** Le message ressemble à une question sur l'établissement (mots-clés de la FAQ, ou point d'interrogation final). */
    public function isQuestion(string $raw): bool
    {
        return Keywords::contains(Text::normalize($raw), 'faq') || str_ends_with(trim($raw), '?');
    }
}
