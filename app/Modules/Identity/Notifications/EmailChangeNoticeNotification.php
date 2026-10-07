<?php

declare(strict_types=1);

namespace App\Modules\Identity\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avis envoyé à l'ANCIENNE adresse e-mail (flowchart 3C) : un changement est demandé, puis effectué. Notification de sécurité.
 */
final class EmailChangeNoticeNotification extends Notification
{
    public const REQUESTED = 'requested';

    public const DONE = 'done';

    /**
     * @param  self::REQUESTED|self::DONE  $stage
     */
    public function __construct(private readonly string $stage, private readonly string $newEmail, private readonly string $lang) {}

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
            ->subject(__("mail.email_change.{$this->stage}.subject", [], $this->lang))
            ->greeting(__('mail.greeting', [], $this->lang))
            ->line(__("mail.email_change.{$this->stage}.intro", ['email' => $this->newEmail], $this->lang))
            ->line(__('mail.email_change.not_you', [], $this->lang));
    }
}
