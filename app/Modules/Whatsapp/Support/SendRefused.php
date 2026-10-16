<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

use RuntimeException;

/** Envoi refusé avant tout appel réseau : `no_account`, `opted_out` (STOP) `window_closed` (plus de 24 h sans message du client) ou `no_template` (aucun modèle approuvé pour ce secteur et ce déclencheur). */
final class SendRefused extends RuntimeException
{
    public const NO_ACCOUNT = 'no_account';

    public const OPTED_OUT = 'opted_out';

    public const WINDOW_CLOSED = 'window_closed';

    public const NO_TEMPLATE = 'no_template';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Envoi WhatsApp refusé ({$reason}).");
    }
}
