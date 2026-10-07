<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Pause d'établissement : confirmation, rappel avant la fin et réactivation automatique. Envoyée au propriétaire, dans sa langue.
 */
final class PauseNotification extends Notification
{
    public const STARTED = 'started';

    public const ENDING = 'ending';

    public const ENDED = 'ended';

    public const SUSPENSION_ENDING = 'suspension_ending';

    /**
     * @param  self::STARTED|self::ENDING|self::ENDED|self::SUSPENSION_ENDING  $stage
     */
    public function __construct(private readonly string $stage, private readonly string $organizationName, private readonly ?CarbonInterface $until, private readonly string $lang) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $key = "mail.pause.{$this->stage}";
        $params = ['name' => $this->organizationName, 'date' => $this->until?->copy()->locale($this->lang)->isoFormat('DD/MM/YYYY') ?? ''];

        return (new MailMessage)
            ->subject(__("{$key}.subject", $params, $this->lang))
            ->greeting(__('mail.greeting', [], $this->lang))
            ->line(__("{$key}.intro", $params, $this->lang))
            ->line(__("{$key}.hint", $params, $this->lang));
    }
}
