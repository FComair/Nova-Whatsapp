<?php

declare(strict_types=1);

namespace App\Modules\Support\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation de réception d'une demande de support (docs/22 §7). Envoyée à l'adresse de réponse choisie sur le formulaire.
 */
final class TicketCreatedNotification extends Notification
{
    public function __construct(
        private readonly string $reference,
        private readonly string $subject,
        private readonly string $category,
        private readonly string $urgency,
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
            ->subject(__('mail.support.created.subject', ['reference' => $this->reference]))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.support.created.intro'))
            ->line(__('mail.support.created.reference', ['reference' => $this->reference]))
            ->line(__('mail.support.created.summary', [
                'subject' => $this->subject,
                'category' => __('ui.support.categories.'.$this->category),
                'urgency' => __('ui.support.urgencies.'.$this->urgency.'.label'),
            ]))
            ->action(__('mail.support.action'), $this->url)
            ->line(__('mail.support.created.response_time'));
    }
}
