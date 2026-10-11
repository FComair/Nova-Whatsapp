<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invitation (ou réinitialisation du mot de passe) d'un administrateur : un lien à usage unique qui expire. Notification de sécurité,
 * non désactivable.
 */
final class AdminAccountInvitation extends Notification
{
    public function __construct(private readonly string $url, private readonly bool $reset, private readonly int $validDays, private readonly string $role = '') {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = $this->reset ? 'admins.mail.reset' : 'admins.mail.invitation';

        return (new MailMessage)
            ->subject(__("{$key}.subject"))
            ->greeting(__('admins.mail.greeting', ['name' => method_exists($notifiable, 'getFilamentName') ? $notifiable->getFilamentName() : '']))
            ->line(__("{$key}.intro", ['role' => $this->role]))
            ->action(__("{$key}.action"), $this->url)
            ->line(__('admins.mail.valid', ['days' => $this->validDays]))
            ->line(__('admins.mail.mfa'))
            ->line(__('admins.mail.ignore'));
    }
}
