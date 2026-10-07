<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirme au propriétaire que ses données ont été effacées. Envoyée une seule fois, puis son adresse est oubliée.
 */
final class DeletionCompletedNotification extends Notification
{
    public function __construct(private readonly string $organizationName, string $locale)
    {
        $this->locale(in_array($locale, (array) config('nova.locales'), true) ? $locale : (string) config('app.fallback_locale'));
    }

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
            ->subject(__('mail.deletion_completed.subject', ['name' => $this->organizationName]))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.deletion_completed.intro', ['name' => $this->organizationName]))
            ->line(__('mail.deletion_completed.retention'));
    }
}
