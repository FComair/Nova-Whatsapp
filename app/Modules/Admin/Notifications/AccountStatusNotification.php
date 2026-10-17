<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient le propriétaire d'une décision de la plateforme sur son compte (suspension, levée, suppression programmée ou annulée, essai prolongé).
 * Notification légale et de sécurité : non désactivable (docs/02 §1). Envoyée dans la langue du propriétaire.
 */
final class AccountStatusNotification extends Notification
{
    private const KEYS = [
        'organization.suspended' => 'suspended',
        'organization.suspension_lifted' => 'suspension_lifted',
        'organization.deletion_scheduled' => 'deletion_scheduled',
        'organization.deletion_immediate' => 'deletion_scheduled',
        'organization.deletion_cancelled' => 'deletion_cancelled',
        'organization.trial_extended' => 'trial_extended',
    ];

    public function __construct(
        private readonly string $action,
        private readonly string $organizationName,
        private readonly ?string $reason,
        private readonly ?CarbonInterface $deletionDate,
        string $locale,
        private readonly ?CarbonInterface $trialEnd = null,
    ) {
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
        $key = 'mail.account_status.'.self::KEYS[$this->action];
        $mail = (new MailMessage)
            ->subject(__("{$key}.subject", ['name' => $this->organizationName]))
            ->greeting(__('mail.greeting'))
            ->line(__("{$key}.intro", ['name' => $this->organizationName]));

        if ($this->reason !== null) {
            $mail->line(__('mail.account_status.reason', ['reason' => $this->reason]));
        }

        if ($this->deletionDate !== null && in_array($this->action, ['organization.deletion_scheduled', 'organization.deletion_immediate'], true)) {
            $mail->line(__('mail.account_status.deletion_date', ['date' => $this->deletionDate->locale($this->locale ?? 'fr')->isoFormat('DD/MM/YYYY')]));
        }

        if ($this->trialEnd !== null && $this->action === 'organization.trial_extended') {
            $mail->line(__('mail.account_status.trial_end', ['date' => $this->trialEnd->locale($this->locale ?? 'fr')->isoFormat('DD/MM/YYYY')]));
        }

        return $mail->line(__('mail.account_status.contact'));
    }
}
