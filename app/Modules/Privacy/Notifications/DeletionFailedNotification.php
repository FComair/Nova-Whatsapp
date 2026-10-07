<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte le fondateur (erreur E12) : une étape d'effacement a échoué trois fois. Ne contient aucune donnée personnelle :
 * seulement l'identifiant de l'organisation, l'étape et le code d'erreur.
 */
final class DeletionFailedNotification extends Notification
{
    public function __construct(private readonly string $orgId, private readonly string $step, private readonly string $errorCode) {}

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
            ->subject(__('mail.deletion_failed.subject', [], 'fr'))
            ->greeting(__('mail.greeting', [], 'fr'))
            ->line(__('mail.deletion_failed.intro', ['org' => $this->orgId, 'step' => $this->step, 'error' => $this->errorCode], 'fr'))
            ->line(__('mail.deletion_failed.action', [], 'fr'));
    }
}
