<?php

declare(strict_types=1);

namespace App\Modules\Identity\Exceptions;

use RuntimeException;

/**
 * Trop de tentatives ou d'envois : catalogue d'erreurs E2 et E3 (flowchart 5A).
 */
final class TooManyAttempts extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $retryAfterSeconds)
    {
        parent::__construct("Trop de tentatives ({$errorCode}).");
    }

    public function retryAfterMinutes(): int
    {
        return max(1, (int) ceil($this->retryAfterSeconds / 60));
    }
}
