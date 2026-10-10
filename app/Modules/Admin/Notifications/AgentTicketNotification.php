<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notification d'un agent du support (docs/29 §7) : dans la cloche du panel (`database`) et/ou par e-mail selon ses préférences.
 * Le texte vient de `tickets.notify.{event}` ; il ne contient que la référence, l'objet et le nom du compte client, jamais le message.
 */
final class AgentTicketNotification extends Notification
{
    /**
     * @param  list<string>  $channels  « database » et/ou « mail »
     * @param  array<string, string>  $params  remplacements du texte (reference, subject, organization, priority, status…)
     */
    public function __construct(
        private readonly string $event,
        private readonly array $channels,
        private readonly array $params,
        private readonly string $url,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title(__("tickets.notify.{$this->event}.title", $this->params))
            ->body(__("tickets.notify.{$this->event}.body", $this->params))
            ->actions([Action::make('view')->label(__('tickets.notify.view'))->url($this->url)->markAsRead()])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__("tickets.notify.{$this->event}.title", $this->params))
            ->line(__("tickets.notify.{$this->event}.body", $this->params))
            ->action(__('tickets.notify.view'), $this->url);
    }
}
