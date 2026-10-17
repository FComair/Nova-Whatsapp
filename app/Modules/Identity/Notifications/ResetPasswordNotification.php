<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Lien de réinitialisation valable 60 min, à usage unique (flowchart 2A).
 */
final class ResetPasswordNotification extends Notification
{
    public function __construct(private readonly string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $minutes = (int) config('rendivoo.security.password_reset_ttl_minutes');

        $url = url(route('password.reset', ['token' => $this->token, 'email' => $notifiable->email], false));

        return (new MailMessage)
            ->subject(__('mail.reset_password.subject'))
            ->greeting(__('mail.greeting'))
            ->line(__('mail.reset_password.intro'))
            ->action(__('mail.reset_password.action'), $url)
            ->line(__('mail.reset_password.expires', ['minutes' => $minutes]))
            ->line(__('mail.ignore_if_not_you'));
    }
}
