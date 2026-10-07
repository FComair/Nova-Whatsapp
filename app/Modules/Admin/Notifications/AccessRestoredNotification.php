<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient la personne dont le support a réinitialisé l'accès (double authentification, éventuellement mot de passe).
 * Notification de sécurité : non désactivable.
 */
final class AccessRestoredNotification extends Notification
{
    public function __construct(private readonly bool $passwordReset, private readonly string $lang) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('mail.access_restored.subject', [], $this->lang))
            ->greeting(__('mail.greeting', [], $this->lang))
            ->line(__('mail.access_restored.intro', [], $this->lang))
            ->line(__('mail.access_restored.next_login', [], $this->lang));

        if ($this->passwordReset) {
            $message->line(__('mail.access_restored.password_reset', [], $this->lang));
        }

        return $message->line(__('mail.access_restored.not_you', [], $this->lang));
    }
}
