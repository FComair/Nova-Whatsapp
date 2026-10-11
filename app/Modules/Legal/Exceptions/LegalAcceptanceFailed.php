<?php

declare(strict_types=1);

namespace App\Modules\Legal\Exceptions;

use RuntimeException;
use Throwable;

/**
 * L'acceptation des documents juridiques n'a pas pu être enregistrée (docs/28) : la création du compte est annulée, rien n'est gardé à moitié.
 */
final class LegalAcceptanceFailed extends RuntimeException
{
    /**
     * @param  list<string>  $missing  documents dont l'acceptation n'a pas été enregistrée
     */
    public function __construct(public readonly array $missing, ?Throwable $previous = null)
    {
        parent::__construct('Acceptation juridique non enregistrée : '.implode(', ', $missing), 0, $previous);
    }
}
