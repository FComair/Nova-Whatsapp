<?php

declare(strict_types=1);

namespace App\Modules\Team\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient un membre d'un changement de son accès (permissions modifiées, suspension, réactivation, retrait). Notification de sécurité,
 * non désactivable, envoyée dans sa langue. Elle ne détaille jamais les permissions : il les voit en se connectant.
 */
final class MemberAccessNotification extends Notification
{
    public const EVENTS = ['access_changed', 'suspended', 'reactivated', 'removed'];

    public function __construct(private readonly string $organizationName, private readonly string $event) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = "mail.team_access.{$this->event}";

        return (new MailMessage)
            ->subject(__("{$key}.subject", ['organization' => $this->organizationName]))
            ->greeting(__('mail.greeting'))
            ->line(__("{$key}.intro", ['organization' => $this->organizationName]))
            ->line(__('mail.team_access.contact'));
    }
}
