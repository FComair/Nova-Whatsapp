<?php

declare(strict_types=1);

namespace App\Modules\Staff\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * E-mail d'invitation d'un praticien. Envoyé dans la langue de l'entreprise qui invite.
 */
final class PractitionerInvitationNotification extends Notification
{
    public function __construct(
        private readonly string $organizationName,
        private readonly string $practitionerName,
        private readonly string $token,
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
        $days = (int) config('nova.security.invitation_ttl_days');

        return (new MailMessage)
            ->subject(__('mail.invitation.subject', ['organization' => $this->organizationName]))
            ->greeting(__('mail.invitation.greeting', ['name' => $this->practitionerName]))
            ->line(__('mail.invitation.intro', ['organization' => $this->organizationName]))
            ->action(__('mail.invitation.action'), url(route('invitation.show', ['token' => $this->token], false)))
            ->line(__('mail.invitation.expires', ['days' => $days]))
            ->line(__('mail.ignore_if_not_you'));
    }
}
