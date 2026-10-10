<?php

declare(strict_types=1);

namespace App\Modules\Admin\Filament\Resources\SupportTicketResource\Pages;

use App\Modules\Admin\Filament\Resources\OrganizationResource;
use App\Modules\Admin\Filament\Resources\SupportTicketResource;
use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Models\PlatformSupportTicket;
use App\Modules\Admin\Services\PlatformAudit;
use App\Modules\Admin\Services\SupportConsole;
use App\Modules\Admin\Support\SupportAccess;
use App\Modules\Admin\Support\SupportPermissions;
use App\Modules\Support\Exceptions\TicketChanged;
use App\Modules\Support\Exceptions\TicketRefused;
use App\Modules\Support\Models\SupportAttachment;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketEvent;
use App\Modules\Support\Models\SupportTicketLink;
use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Services\SupportSla;
use App\Modules\Support\Services\TicketDesk;
use App\Modules\Support\Support\TicketStatus;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Détail d'un ticket (docs/29 §4) : en-tête, conversation (réponses au client, notes internes, événements système), zone de réponse,
 * actions (assigner, état, priorité, étiquettes, liens, aide interne, résoudre, fermer, rouvrir).
 *
 * Concurrence : l'écran retient la version du ticket à l'ouverture. Si quelqu'un d'autre le modifie, un bandeau l'indique (relevé toutes les
 * 30 s) et les actions sont refusées tant que l'agent n'a pas actualisé : son brouillon est conservé.
 *
 * @property-read Schema $form
 */
class ViewSupportTicket extends Page
{
    protected static string $resource = SupportTicketResource::class;

    protected string $view = 'admin.support.ticket';

    public string $ticketId = '';

    public int $version = 0;

    public bool $stale = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $admin = SupportAccess::admin() ?? abort(403);

        try {
            $ticket = app(SupportConsole::class)->open($admin, (string) $record);
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->ticketId = $ticket->id;
        $this->version = $ticket->version;
        $this->form->fill(['mode' => 'reply', 'body' => '', 'status' => null, 'files' => []]);
    }

    public function getTitle(): string|Htmlable
    {
        $ticket = $this->ticket();

        return $ticket->reference().' · '.$ticket->subject;
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [SupportTicketResource::getUrl() => __('tickets.plural'), $this->ticket()->reference()];
    }

    /** Le ticket à jour, lu avec le rôle de la plateforme et dans la portée de l'agent. */
    public function ticket(): PlatformSupportTicket
    {
        return app(SupportConsole::class)->find(SupportAccess::admin() ?? abort(403), $this->ticketId);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->partiallyRender(false)->components([
            ToggleButtons::make('mode')->label(__('tickets.composer.mode'))->inline()->grouped()->live()->required()
                ->options(['reply' => __('tickets.composer.reply'), 'internal' => __('tickets.composer.internal')])
                ->icons(['reply' => 'heroicon-m-paper-airplane', 'internal' => 'heroicon-m-lock-closed'])
                ->colors(['reply' => 'success', 'internal' => 'warning']),
            Textarea::make('body')->label(fn (callable $get): string => $get('mode') === 'internal' ? __('tickets.composer.note_label') : __('tickets.composer.reply_label'))
                ->rows(6)->required()->maxLength(10000)
                ->helperText(fn (callable $get): string => $get('mode') === 'internal' ? __('tickets.composer.note_help') : __('tickets.composer.reply_help', ['email' => $this->ticket()->reply_email])),
            Select::make('status')->label(__('tickets.composer.status'))->placeholder(__('tickets.composer.status_auto'))->native(false)
                ->options(fn (): array => collect(TicketStatus::STAFF_CHOICES)->mapWithKeys(fn (string $status): array => [$status => __("tickets.statuses.{$status}")])->all())
                ->visible(fn (callable $get): bool => $get('mode') !== 'internal'),
            FileUpload::make('files')->label(__('tickets.composer.files'))->multiple()->storeFiles(false)->disk('local')
                ->maxFiles(AttachmentStore::MAX_FILES)->maxSize(AttachmentStore::MAX_KILOBYTES)->acceptedFileTypes(['image/png', 'image/jpeg', 'application/pdf'])
                ->helperText(__('tickets.composer.files_help', ['count' => AttachmentStore::MAX_FILES, 'size' => AttachmentStore::MAX_KILOBYTES / 1024])),
        ]);
    }

    /** Envoie la réponse au client, ou enregistre la note interne selon le mode choisi. */
    public function submit(): void
    {
        $data = $this->form->getState();
        $internal = ($data['mode'] ?? 'reply') === 'internal';

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) ($data['files'] ?? []), fn (mixed $file): bool => $file instanceof UploadedFile));
        $body = (string) $data['body'];
        $status = $internal || ($data['status'] ?? null) === null ? null : (string) $data['status'];

        $sent = $this->perform(fn (SupportConsole $console, $admin, PlatformSupportTicket $ticket) => $internal
            ? $console->note($admin, $ticket, $body, $files)
            : $console->reply($admin, $ticket, $body, $status, $files, $this->version), $internal ? __('tickets.composer.note_saved') : __('tickets.composer.reply_sent'));

        if ($sent) {
            $this->form->fill(['mode' => $data['mode'] ?? 'reply', 'body' => '', 'status' => null, 'files' => []]);
        }
    }

    /** Recharge l'état à jour : l'agent a vu les changements des autres, les actions sont de nouveau permises. */
    public function refreshTicket(): void
    {
        $this->version = $this->ticket()->version;
        $this->stale = false;
    }

    /** Relevé périodique : quelqu'un d'autre a-t-il modifié le ticket depuis l'ouverture de l'écran ? */
    public function checkForChanges(): void
    {
        if ($this->ticket()->version !== $this->version) {
            $this->stale = true;
        }
    }

    public function removeTag(string $tag): void
    {
        $this->perform(fn (SupportConsole $console, $admin, PlatformSupportTicket $ticket) => $console->removeTag($admin, $ticket, $tag));
    }

    public function removeLink(string $linkId): void
    {
        $this->perform(fn (SupportConsole $console, $admin, PlatformSupportTicket $ticket) => $console->unlink($admin, $ticket, $linkId));
    }

    /**
     * Exécute une action du console avec la gestion commune des refus ; renvoie vrai si elle a abouti.
     *
     * @param  callable(SupportConsole, PlatformAdmin, PlatformSupportTicket): mixed  $operation
     */
    private function perform(callable $operation, ?string $success = null): bool
    {
        $admin = SupportAccess::admin() ?? abort(403);

        try {
            $operation(app(SupportConsole::class), $admin, $this->ticket());
        } catch (TicketChanged) {
            $this->stale = true;
            Notification::make()->warning()->title(__('tickets.stale.title'))->body(__('tickets.stale.body'))->send();

            return false;
        } catch (TicketRefused) {
            Notification::make()->danger()->title(__('tickets.refused'))->send();

            return false;
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->version = $this->ticket()->version;
        $this->stale = false;
        Notification::make()->success()->title($success ?? __('tickets.saved'))->send();

        return true;
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        $ticket = $this->ticket();
        $closed = $ticket->status === TicketStatus::CLOSED;

        return [
            Action::make('assign')->label(__('tickets.actions.assign'))->icon('heroicon-m-user-plus')->color('gray')->hidden($closed)
                ->fillForm(fn (): array => ['agent' => $this->ticket()->assigned_admin_id ?? 'none'])
                ->schema([Select::make('agent')->label(__('tickets.columns.assignee'))->options(fn (): array => ['none' => __('tickets.unassigned'), ...SupportAccess::assignableBy(SupportAccess::admin() ?? new PlatformAdmin, $this->ticket()->org_id)])->required()])
                ->action(fn (array $data) => $this->perform(fn (SupportConsole $console, $admin, $ticket) => $console->assign($admin, $ticket, $data['agent'] === 'none' ? null : (string) $data['agent'], $this->version))),
            Action::make('status')->label(__('tickets.actions.status'))->icon('heroicon-m-arrow-path')->color('gray')->hidden($closed)
                ->fillForm(fn (): array => ['status' => in_array($this->ticket()->status, TicketStatus::STAFF_SETTABLE, true) ? $this->ticket()->status : null])
                ->schema([Select::make('status')->label(__('tickets.columns.status'))->required()->native(false)
                    ->options(fn (): array => collect(TicketStatus::STAFF_SETTABLE)->mapWithKeys(fn (string $status): array => [$status => __("tickets.statuses.{$status}")])->all())
                    ->helperText(__('tickets.actions.status_help'))])
                ->action(fn (array $data) => $this->perform(fn (SupportConsole $console, $admin, $ticket) => $console->setStatus($admin, $ticket, (string) $data['status'], $this->version))),
            Action::make('priority')->label(__('tickets.actions.priority'))->icon('heroicon-m-flag')->color('gray')->hidden($closed || ! $this->allowed(SupportPermissions::CHANGE_PRIORITY))
                ->fillForm(fn (): array => ['priority' => $this->ticket()->priority()])
                ->schema([Select::make('priority')->label(__('tickets.columns.priority'))->required()->native(false)
                    ->options(fn (): array => collect(SupportTicket::PRIORITIES)->mapWithKeys(fn (string $priority): array => [$priority => __("tickets.priorities.{$priority}")])->all())])
                ->action(fn (array $data) => $this->perform(fn (SupportConsole $console, $admin, $ticket) => $console->setPriority($admin, $ticket, (string) $data['priority'], $this->version))),
            Action::make('resolve')->label(__('tickets.actions.resolve'))->icon('heroicon-m-check-circle')->color('success')
                ->visible(fn (): bool => TicketStatus::isOpenWork($this->ticket()->status))
                ->requiresConfirmation()->modalHeading(__('tickets.actions.resolve_heading'))->modalDescription(__('tickets.actions.resolve_description'))
                ->action(fn () => $this->perform(fn (SupportConsole $console, $admin, $ticket) => $console->setStatus($admin, $ticket, TicketStatus::RESOLVED, $this->version))),
            Action::make('reopen')->label(__('tickets.actions.reopen'))->icon('heroicon-m-arrow-uturn-left')->color('warning')
                ->visible(fn (): bool => in_array($this->ticket()->status, [TicketStatus::RESOLVED, TicketStatus::CLOSED], true) && $this->allowed(SupportPermissions::REOPEN_TICKETS))
                ->requiresConfirmation()->modalHeading(__('tickets.actions.reopen_heading'))->modalDescription(__('tickets.actions.reopen_description'))
                ->action(fn () => $this->perform(fn (SupportConsole $console, $admin, $ticket) => $console->reopen($admin, $ticket, $this->version))),
            ActionGroup::make([
                Action::make('tags')->label(__('tickets.actions.tag'))->icon('heroicon-m-tag')->hidden($closed || ! $this->allowed(SupportPermissions::MANAGE_TAGS))
                    ->schema([TextInput::make('tag')->label(__('tickets.tag'))->maxLength(30)->required()->helperText(__('tickets.actions.tag_help'))])
                    ->action(fn (array $data) => $this->perform(fn (SupportConsole $console, $admin, $ticket) => $console->addTag($admin, $ticket, (string) $data['tag']))),
                Action::make('link')->label(__('tickets.actions.link'))->icon('heroicon-m-link')
                    ->schema([
                        Select::make('kind')->label(__('tickets.link.kind'))->required()->native(false)->default('booking')
                            ->options(fn (): array => collect(SupportTicketLink::KINDS)->mapWithKeys(fn (string $kind): array => [$kind => __("tickets.link.kinds.{$kind}")])->all()),
                        TextInput::make('label')->label(__('tickets.link.label'))->required()->maxLength(120)->helperText(__('tickets.link.help')),
                    ])
                    ->action(fn (array $data) => $this->perform(fn (SupportConsole $console, $admin, $ticket) => $this->linkTo($console, $admin, $ticket, (string) $data['kind'], (string) $data['label']))),
                Action::make('help')->label(__('tickets.actions.help'))->icon('heroicon-m-user-group')->hidden($closed)
                    ->modalHeading(__('tickets.actions.help'))->modalDescription(__('tickets.help.description'))
                    ->schema([
                        Select::make('team')->label(__('tickets.help.team'))->required()->native(false)
                            ->options(fn (): array => collect(TicketDesk::TEAMS)->mapWithKeys(fn (string $team): array => [$team => __("tickets.teams.{$team}")])->all()),
                        Textarea::make('message')->label(__('tickets.help.message'))->required()->rows(4)->maxLength(2000),
                    ])
                    ->action(fn (array $data) => $this->perform(fn (SupportConsole $console, $admin, $ticket) => $console->requestHelp($admin, $ticket, (string) $data['team'], (string) $data['message'], $this->version))),
                Action::make('close')->label(__('tickets.actions.close'))->icon('heroicon-m-lock-closed')->color('danger')->hidden($closed)
                    ->requiresConfirmation()->modalHeading(__('tickets.actions.close_heading'))->modalDescription(__('tickets.actions.close_description'))
                    ->action(fn () => $this->perform(fn (SupportConsole $console, $admin, $ticket) => $console->close($admin, $ticket, $this->version))),
            ])->label(__('tickets.actions.more'))->icon('heroicon-m-ellipsis-horizontal')->button()->color('gray'),
            $this->deleteAction(),
        ];
    }

    private function allowed(string $permission): bool
    {
        $admin = SupportAccess::admin();

        return $admin !== null && SupportPermissions::can($admin, $permission);
    }

    /**
     * Suppression définitive : visible seulement avec la permission, confirmée en saisissant la référence du ticket (docs/32 §1).
     */
    private function deleteAction(): Action
    {
        return Action::make('delete')->label(__('tickets.delete.label'))->icon('heroicon-m-trash')->color('danger')
            ->visible(fn (): bool => $this->allowed(SupportPermissions::DELETE_TICKETS))
            ->modalHeading(fn (): string => __('tickets.delete.heading', ['reference' => $this->ticket()->reference()]))
            ->modalDescription(__('tickets.delete.description'))
            ->modalIcon('heroicon-o-exclamation-triangle')->modalSubmitActionLabel(__('tickets.delete.submit'))
            ->schema([
                TextInput::make('confirmation')->label(fn (): string => __('tickets.delete.confirm_label', ['reference' => $this->ticket()->reference()]))->required()->autocomplete(false)
                    ->in(fn (): array => [$this->ticket()->reference()])->validationMessages(['in' => __('tickets.delete.confirm_mismatch')]),
                Textarea::make('reason')->label(__('tickets.delete.reason'))->rows(2)->maxLength(500)->helperText(__('tickets.delete.reason_help')),
            ])
            ->action(function (array $data): void {
                $admin = SupportAccess::admin() ?? abort(403);
                $reference = $this->ticket()->reference();
                $reason = trim((string) ($data['reason'] ?? ''));

                try {
                    app(SupportConsole::class)->delete($admin, $this->ticketId, $reason === '' ? null : $reason);
                } catch (AuthorizationException) {
                    abort(403);
                }

                Notification::make()->success()->title(__('tickets.delete.done', ['reference' => $reference]))->send();
                $this->redirect(SupportTicketResource::getUrl('index'));
            });
    }

    /** Un lien vers un autre ticket se saisit par sa référence (NWS-1042) ; on retrouve alors le ticket pour le relier à son identifiant. */
    private function linkTo(SupportConsole $console, PlatformAdmin $admin, PlatformSupportTicket $ticket, string $kind, string $label): void
    {
        $refId = null;

        if ($kind === 'ticket') {
            $number = SupportTicket::numberFrom($label);
            $target = $number === null ? null : $console->find($admin, (string) PlatformSupportTicket::query()->where('reference_number', $number)->value('id'));
            $refId = $target?->id;
            $label = $target === null ? $label : $target->reference().' · '.$target->subject;
        }

        $console->link($admin, $ticket, $kind, $label, $refId);
    }

    // ---- Données de la vue ------------------------------------------------------------------------------------------------------------

    /**
     * Fil chronologique : messages (client, équipe, notes internes) et événements système.
     *
     * @return list<array<string, mixed>>
     */
    public function thread(): array
    {
        $ticket = $this->ticket();
        $agents = DB::connection(PlatformAudit::CONNECTION)->table('platform_admins')->pluck('email', 'id');
        $items = [];

        /** @var Collection<int, SupportMessage> $messages */
        $messages = $ticket->messages()->with('attachments')->orderBy('created_at')->orderBy('id')->get();

        foreach ($messages as $message) {
            $items[] = [
                'type' => 'message',
                'kind' => $message->visibility === 'internal' ? 'internal' : ($message->author_type === 'staff' ? 'staff' : 'client'),
                'id' => $message->id,
                'author' => $message->author_type === 'staff' ? ($agents[$message->author_id] ?? __('tickets.thread.nova_team')) : $ticket->reply_email,
                'body' => $message->body,
                'at' => $message->created_at,
                'attachments' => $message->attachments->map(fn (SupportAttachment $file): array => ['id' => $file->id, 'name' => $file->original_name, 'size' => $file->size])->all(),
            ];
        }

        $skip = ['internal_note', 'staff_reply', 'help_requested'];

        /** @var Collection<int, SupportTicketEvent> $events */
        $events = $ticket->events()->whereNotIn('action', $skip)->orderBy('created_at')->get();

        foreach ($events as $event) {
            $items[] = ['type' => 'event', 'id' => $event->id, 'text' => $this->eventText($event, $agents, $ticket->source), 'internal' => $event->visibility === 'internal', 'at' => $event->created_at];
        }

        usort($items, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $items;
    }

    /**
     * @param  Collection<string, string>  $agents  e-mail par identifiant de compte
     */
    private function eventText(SupportTicketEvent $event, Collection $agents, string $source): string
    {
        $who = match ($event->actor_type) {
            'staff' => (string) ($agents[$event->actor_id] ?? __('tickets.thread.nova_team')),
            'client' => __('tickets.thread.client'),
            default => __('tickets.thread.system'),
        };
        $meta = $event->meta ?? [];
        $label = fn (?string $status): string => $status === null ? '—' : __("tickets.statuses.{$status}");

        return __("tickets.events.{$event->action}", [
            'who' => $who,
            'from' => $label($event->from_status),
            'to' => $label($event->to_status),
            'note' => (string) $event->note,
            'assignee' => (string) ($agents[$meta['to'] ?? ''] ?? __('tickets.unassigned')),
            'previous' => (string) ($agents[$meta['from'] ?? ''] ?? __('tickets.unassigned')),
            'priority_from' => isset($meta['from']) ? __('tickets.priorities.'.$meta['from']) : '—',
            'priority_to' => isset($meta['to']) ? __('tickets.priorities.'.$meta['to']) : '—',
            'source' => __('tickets.sources.'.($meta['source'] ?? $source)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $ticket = $this->ticket();
        $agents = SupportAccess::names();
        $sla = app(SupportSla::class);
        $organization = DB::connection(PlatformAudit::CONNECTION)->table('organizations')->where('id', $ticket->org_id)->value('name');

        return [
            'ticket' => $ticket,
            'organization' => (string) $organization,
            'organization_url' => OrganizationResource::canAccess() ? OrganizationResource::getUrl('view', ['record' => $ticket->org_id]) : null,
            'assignee' => $ticket->assigned_admin_id === null ? null : ($agents[$ticket->assigned_admin_id] ?? null),
            'sla' => $sla->stateOf($ticket),
            'tags' => $ticket->tags()->orderBy('tag')->pluck('tag')->all(),
            'links' => $ticket->links()->orderBy('created_at')->get(),
            'closed' => $ticket->status === TicketStatus::CLOSED,
        ];
    }
}
