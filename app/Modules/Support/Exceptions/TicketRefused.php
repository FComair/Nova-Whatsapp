<?php

declare(strict_types=1);

namespace App\Modules\Support\Exceptions;

use RuntimeException;

/**
 * Une action sur un ticket n'est pas permise dans son état actuel (par exemple répondre à un ticket fermé).
 */
final class TicketRefused extends RuntimeException
{
    public static function closed(): self
    {
        return new self('closed');
    }

    public static function state(string $action): self
    {
        return new self($action);
    }
}
