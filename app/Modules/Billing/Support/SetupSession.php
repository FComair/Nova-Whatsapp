<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

/** Page hébergée de saisie du moyen de paiement. */
final readonly class SetupSession
{
    public function __construct(public string $ref, public string $url) {}
}
