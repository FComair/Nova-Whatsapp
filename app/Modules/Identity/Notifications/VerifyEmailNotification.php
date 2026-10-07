<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Lien de vérification de l'adresse e-mail, signé et valable 24 h (flowchart 1A).
 */
final class VerifyEmailNotification extends Notification
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $hours = (int) config('nova.security.email_link_ttl_hours');

        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addHours($hours),
            ['user' => $notifiable->id, 'hash' => sha1($notifiable->email)],
        );

        return (new MailMessage)
            ->subject(__('mail.verify_email.subject'))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.verify_email.intro'))
            ->action(__('mail.verify_email.action'), $url)
            ->line(__('mail.verify_email.expires', ['hours' => $hours]))
            ->line(__('mail.ignore_if_not_you'));
    }
}
