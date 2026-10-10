<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation (ou réinitialisation de mot de passe) d'un membre de l'équipe de support : un lien à usage unique qui expire.
 * Notification de sécurité, non désactivable.
 */
final class SupportAgentInvitation extends Notification
{
    public function __construct(private readonly string $url, private readonly bool $reset, private readonly int $validDays) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = $this->reset ? 'team.mail.reset' : 'team.mail.invitation';

        return (new MailMessage)
            ->subject(__("{$key}.subject"))
            ->greeting(__('team.mail.greeting', ['name' => method_exists($notifiable, 'getFilamentName') ? $notifiable->getFilamentName() : '']))
            ->line(__("{$key}.intro"))
            ->action(__("{$key}.action"), $this->url)
            ->line(__('team.mail.valid', ['days' => $this->validDays]))
            ->line(__('team.mail.mfa'))
            ->line(__('team.mail.ignore'));
    }
}
