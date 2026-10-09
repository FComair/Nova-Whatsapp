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

    /** Pourboire sur un rendez-vous dont le paiement est « non payé » ou « perte » : aucun argent n'a été reçu. */
    public const TIP_REQUIRES_PAYMENT = 'tip_requires_payment';

    /** Aucun praticien actif n'est attribué au rendez-vous : une personne autorisée doit choisir qui reçoit le pourboire. */
    public const TIP_RECIPIENT_REQUIRED = 'tip_recipient_required';

    /** Bénéficiaire inconnu, inactif, ou indiqué deux fois. */
    public const TIP_RECIPIENT_INVALID = 'tip_recipient_invalid';

    /** Les parts ne totalisent pas le pourboire. */
    public const TIP_ALLOCATION_MISMATCH = 'tip_allocation_mismatch';

    public function __construct(public readonly string $rule)
    {
        parent::__construct("Règle de réservation non respectée : {$rule}.");
    }
}
