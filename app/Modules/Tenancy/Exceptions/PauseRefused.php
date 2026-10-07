<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Pause impossible : `$reason` (not_available, days, upcoming_bookings, not_paused) est une clé de traduction ; `$detail` s'y insère.
 */
final class PauseRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $detail = '')
    {
        parent::__construct("pause refused: {$reason}");
    }
}
