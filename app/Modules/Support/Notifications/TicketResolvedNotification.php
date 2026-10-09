<?php

declare(strict_types=1);

namespace App\Modules\Support\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le ticket est résolu (docs/22 §7) : le client peut répondre dans les jours qui suivent, sinon il se ferme de lui-même.
 */
final class TicketResolvedNotification extends Notification
{
    public function __construct(
        private readonly string $reference,
        private readonly string $subject,
        private readonly string $url,
        private readonly int $days,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.support.resolved.subject', ['reference' => $this->reference]))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.support.resolved.intro', ['subject' => $this->subject]))
            ->line(__('mail.support.resolved.reopen', ['days' => $this->days]))
            ->action(__('mail.support.action'), $this->url);
    }
}
