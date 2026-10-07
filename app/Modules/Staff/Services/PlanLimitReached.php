<?php

declare(strict_types=1);

namespace App\Modules\Staff\Services;

use RuntimeException;

/**
 * Limite de l'offre atteinte (erreur E8, flowchart 5B).
 */
final class PlanLimitReached extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("Limite de l'offre atteinte ({$limit}).");
    }
}
