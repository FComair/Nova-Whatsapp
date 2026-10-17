<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Lien de confirmation envoyé à la NOUVELLE adresse e-mail (flowchart 3C) : signé, valable 24 h, à usage unique.
 */
final class ConfirmNewEmailNotification extends Notification
{
    public function __construct(private readonly string $token, private readonly string $lang) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = (int) config('rendivoo.security.email_link_ttl_hours');
        $url = URL::temporarySignedRoute('security.email.confirm', now()->addHours($hours), ['token' => $this->token]);

        return (new MailMessage)
            ->subject(__('mail.confirm_new_email.subject', [], $this->lang))
            ->greeting(__('mail.greeting', [], $this->lang))
            ->line(__('mail.confirm_new_email.intro', [], $this->lang))
            ->action(__('mail.confirm_new_email.action', [], $this->lang), $url)
            ->line(__('mail.confirm_new_email.expires', ['hours' => $hours], $this->lang))
            ->line(__('mail.ignore_if_not_you', [], $this->lang));
    }
}
