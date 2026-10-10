<?php

declare(strict_types=1);

namespace App\Modules\Support\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * L'équipe a changé l'état du ticket sans message (docs/29 §7) : « En attente de vous », « En cours », « Ouvert » (réouverture).
 * `$status` est l'état tel que le client le voit.
 */
final class TicketStatusNotification extends Notification
{
    public function __construct(
        private readonly string $reference,
        private readonly string $subject,
        private readonly string $status,
        private readonly string $url,
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
            ->subject(__('mail.support.status.subject', ['reference' => $this->reference]))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.support.status.intro', ['subject' => $this->subject]))
            ->line(__("mail.support.status.{$this->status}"))
            ->action(__('mail.support.action'), $this->url);
    }
}
