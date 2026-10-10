<?php

declare(strict_types=1);

namespace App\Modules\Team\Exceptions;

use RuntimeException;

/**
 * Une action sur l'équipe est refusée (droit manquant, propriétaire protégé, adresse déjà utilisée…). Le message est une clé de
 * traduction `ui.team.errors.*` : jamais une erreur serveur, toujours une phrase claire pour la personne qui gère l'équipe.
 */
final class TeamRefused extends RuntimeException
{
    public static function because(string $key): self
    {
        return new self($key);
    }

    public function message(): string
    {
        return __("ui.team.errors.{$this->getMessage()}");
    }
}
