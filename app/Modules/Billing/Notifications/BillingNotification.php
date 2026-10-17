<?php

declare(strict_types=1);

namespace App\Modules\Billing\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Messages de facturation au propriétaire (langue du propriétaire) : fin d'essai proche ou atteinte, paiement refusé (relances),
 * abonnement terminé. Notifications légales et de facturation : non désactivables (docs/02 §1).
 * Textes : `mail.billing.{genre}.subject|intro|action`.
 */
final class BillingNotification extends Notification
{
    public const PAYMENT_FAILED = 'payment_failed';

    public const PAYMENT_FAILED_FINAL = 'payment_failed_final';

    public const TRIAL_ENDING = 'trial_ending';

    public const TRIAL_ENDED = 'trial_ended';

    public const SUBSCRIPTION_ENDED = 'subscription_ended';

    /**
     * @param  array<string, string|int>  $params  variables des textes (:date, :attempt, :max, :amount…)
     */
    public function __construct(private readonly string $kind, private readonly array $params, string $locale)
    {
        $this->locale(in_array($locale, (array) config('rendivoo.locales'), true) ? $locale : (string) config('app.fallback_locale'));
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
        $key = 'mail.billing.'.$this->kind;

        return (new MailMessage)
            ->subject(__("{$key}.subject", $this->params))
            ->greeting(__('mail.greeting'))
            ->line(__("{$key}.intro", $this->params))
            ->line(__("{$key}.action", $this->params))
            ->action(__('mail.billing.button'), url('/facturation'));
    }
}
