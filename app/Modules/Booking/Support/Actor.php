<?php

declare(strict_types=1);

namespace App\Modules\Booking\Support;

/**
 * Qui agit sur une réservation : inscrit dans le journal d'événements (`booking_events.actor_type`).
 */
final readonly class Actor
{
    private function __construct(public string $type, public ?string $id) {}

    /** Membre de l'équipe depuis le dashboard. */
    public static function user(?string $id): self
    {
        return new self('user', $id);
    }

    /** Client final, par WhatsApp. Il est soumis à la fenêtre d'annulation. */
    public static function customer(?string $id): self
    {
        return new self('customer', $id);
    }

    public static function staffWhatsapp(?string $id): self
    {
        return new self('staff_whatsapp', $id);
    }

    public static function system(): self
    {
        return new self('system', null);
    }

    public static function api(?string $id = null): self
    {
        return new self('api', $id);
    }

    public function isCustomer(): bool
    {
        return $this->type === 'customer';
    }
}
