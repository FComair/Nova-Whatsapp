<?php

declare(strict_types=1);

namespace App\Modules\Admin\Exceptions;

use RuntimeException;

/**
 * Une action sur un compte administrateur est refusée (droit manquant, compte inconnu, état incompatible, risque de se verrouiller dehors…).
 * Le message est une clé de traduction `admins.errors.*`.
 */
final class AdminRefused extends RuntimeException
{
    public static function because(string $key): self
    {
        return new self($key);
    }

    public function message(): string
    {
        return __("admins.errors.{$this->getMessage()}");
    }
}
