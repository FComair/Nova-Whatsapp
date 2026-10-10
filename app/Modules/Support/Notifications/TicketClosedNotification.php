<?php

declare(strict_types=1);

namespace App\Modules\Support\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Le ticket est fermé par l'équipe (docs/29 §7). Un ticket fermé n'accepte plus de réponse : il faut ouvrir une nouvelle demande.
 */
final class TicketClosedNotification extends Notification
{
    public function __construct(
        private readonly string $reference,
        private readonly string $subject,
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
            ->subject(__('mail.support.closed.subject', ['reference' => $this->reference]))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.support.closed.intro', ['subject' => $this->subject]))
            ->line(__('mail.support.closed.new_ticket'))
            ->action(__('mail.support.action'), $this->url);
    }
}
