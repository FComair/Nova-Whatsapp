<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Lien de confirmation de la suppression du compte (flowchart 4C, décision 12) : signé, valable 24 h, à usage unique.
 */
final class ConfirmDeletionNotification extends Notification
{
    public function __construct(private readonly string $token, private readonly string $organizationName, private readonly string $lang) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $hours = (int) config('nova.security.email_link_ttl_hours');
        $url = URL::temporarySignedRoute('account.deletion.confirm', now()->addHours($hours), ['token' => $this->token]);

        return (new MailMessage)
            ->subject(__('mail.deletion_confirm.subject', [], $this->lang))
            ->greeting(__('mail.greeting', [], $this->lang))
            ->line(__('mail.deletion_confirm.intro', ['name' => $this->organizationName], $this->lang))
            ->action(__('mail.deletion_confirm.action', [], $this->lang), $url)
            ->line(__('mail.deletion_confirm.expires', ['hours' => $hours], $this->lang))
            ->line(__('mail.deletion_confirm.not_you', [], $this->lang));
    }
}
