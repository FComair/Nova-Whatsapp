<?php

declare(strict_types=1);

namespace App\Modules\Booking\Services;

use RuntimeException;

/**
 * Règle métier de réservation non respectée. La règle permet à l'appelant (dashboard, WhatsApp, API) d'afficher le bon message.
 */
final class BookingRuleViolation extends RuntimeException
{
    public const INVALID_TRANSITION = 'invalid_transition';

    public const CANCELLATION_TOO_LATE = 'cancellation_too_late';

    public const HOLD_EXPIRED = 'hold_expired';

    public const CUSTOMER_BLOCKED = 'customer_blocked';

    public const SERVICE_UNAVAILABLE = 'service_unavailable';

    public const PRACTITIONER_CANNOT_PERFORM = 'practitioner_cannot_perform';

    public const AMOUNT_EXCEEDS_PRICE = 'amount_exceeds_price';

    public const INVALID_PAYMENT_STATUS = 'invalid_payment_status';

    public function __construct(public readonly string $rule)
    {
        parent::__construct("Règle de réservation non respectée : {$rule}.");
    }
}
