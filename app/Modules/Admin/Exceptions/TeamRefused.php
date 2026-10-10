<?php

declare(strict_types=1);

namespace App\Modules\Admin\Exceptions;

use RuntimeException;

/**
 * Une action sur un compte de l'équipe de support est refusée (compte inconnu, état incompatible, tickets à réaffecter d'abord…).
 * Le message est une clé de traduction `team.errors.*`.
 */
final class TeamRefused extends RuntimeException
{
    public static function because(string $key): self
    {
        return new self($key);
    }

    public function message(): string
    {
        return __("team.errors.{$this->getMessage()}");
    }
}
