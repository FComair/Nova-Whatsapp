<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte envoyée quand la connexion est verrouillée après des tentatives répétées (flowchart 5A/E3).
 */
final class SuspiciousLoginNotification extends Notification
{
    public function __construct(private readonly int $lockMinutes) {}

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
            ->subject(__('mail.suspicious_login.subject'))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.suspicious_login.intro', ['minutes' => $this->lockMinutes]))
            ->line(__('mail.suspicious_login.advice'));
    }
}
