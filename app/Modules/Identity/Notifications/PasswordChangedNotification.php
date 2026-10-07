<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient le titulaire que son mot de passe a changé (flowcharts 2A et 3C). Notification de sécurité : non désactivable.
 */
final class PasswordChangedNotification extends Notification
{
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
            ->subject(__('mail.password_changed.subject'))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.password_changed.intro'))
            ->line(__('mail.password_changed.other_sessions'))
            ->line(__('mail.password_changed.not_you'));
    }
}
