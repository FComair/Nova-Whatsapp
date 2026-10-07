<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use RuntimeException;

/**
 * Opération de facturation refusée. Le code sert à choisir le message affiché (`ui.billing.errors.*`) :
 * `setup_incomplete`, `payment_failed`, `account_unavailable`, `suspended_by_platform`, `no_subscription`,
 * `limit_practitioners`, `limit_locations`, `already_subscribed`, `no_payment_method`.
 */
final class BillingFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?string $detail = null)
    {
        parent::__construct("Facturation refusée ({$reason}).");
    }
}
