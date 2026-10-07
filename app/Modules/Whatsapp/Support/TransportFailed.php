<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

use RuntimeException;

/** L'envoi vers WhatsApp a échoué (réseau, refus de Meta). Le message d'erreur ne contient jamais le texte envoyé. */
final class TransportFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Envoi WhatsApp échoué ({$errorCode}).");
    }
}
