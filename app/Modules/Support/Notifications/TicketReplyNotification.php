<?php

declare(strict_types=1);

namespace App\Modules\Support\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Nouvelle réponse de l'équipe (docs/22 §7) : un extrait et un lien, jamais toute la conversation ni les pièces jointes.
 */
final class TicketReplyNotification extends Notification
{
    public function __construct(
        private readonly string $reference,
        private readonly string $subject,
        private readonly string $excerpt,
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
            ->subject(__('mail.support.reply.subject', ['reference' => $this->reference]))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.support.reply.intro', ['subject' => $this->subject]))
            ->line('> '.$this->excerpt)
            ->action(__('mail.support.action'), $this->url)
            ->line(__('mail.support.reply.how'));
    }
}
