<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

use RuntimeException;

/**
 * Connexion WhatsApp refusée : `$reason` (invalid_credentials, already_connected, unavailable) est une clé de traduction.
 */
final class ConnectionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("whatsapp connection refused: {$reason}");
    }
}
