<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Controllers;

use App\Modules\Feedback\Services\FeedbackService;
use App\Modules\Help\Support\HelpCatalog;
use App\Modules\Support\Exceptions\TicketRefused;
use App\Modules\Support\Http\Requests\ReplyRequest;
use App\Modules\Support\Http\Requests\StoreTicketRequest;
use App\Modules\Support\Models\SupportAttachment;
use App\Modules\Support\Models\SupportMessage;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketEvent;
use App\Modules\Support\Services\AttachmentStore;
use App\Modules\Support\Services\TicketDesk;
use App\Modules\Support\Support\TicketStatus;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support client (docs/22) : formulaire de contact, liste des tickets, conversation. Chacun ne voit que ses tickets ; le
 * propriétaire voit aussi ceux de son établissement. L'isolation entre établissements est faite par la base (RLS).
 */
final class SupportController
{
    private const PER_PAGE = 25;

    private const CREATE_LIMIT = 5;

    public function __construct(
        private readonly CurrentTenant $current,
        private readonly TicketDesk $desk,
        private readonly HelpCatalog $catalog,
        private readonly FeedbackService $feedback,
    ) {}

    public function create(Request $request): Response
    {
        $locale = app()->getLocale();
        $article = $this->catalog->find($locale, $request->string('category')->toString(), $request->string('article')->toString());

        return Inertia::render('Help/Contact', [
            'email' => (string) $request->user()?->email,
            'token' => (string) Str::uuid(),
            'subject' => $article?->title,
            'options' => ['categories' => SupportTicket::CATEGORIES, 'related' => SupportTicket::RELATED, 'urgencies' => SupportTicket::URGENCIES],
            'limits' => ['files' => AttachmentStore::MAX_FILES, 'kilobytes' => AttachmentStore::MAX_KILOBYTES, 'types' => ['PNG', 'JPG', 'PDF']],
            'index' => $this->catalog->searchIndex($locale),
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $key = 'support-create:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::CREATE_LIMIT)) {
            return back()->withErrors(['rate' => __('ui.support.rate_limited')])->withInput();
        }

        /** @var list<UploadedFile> $files */
        $files = array_values($request->file('attachments', []));

        try {
            $ticket = $this->desk->create($user, $this->current->organization() ?? abort(403), $request->safe()->except('attachments'), $files);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['attachments' => __('ui.support.attachment_invalid')])->withInput();
        }

        if ($ticket->wasRecentlyCreated) {
            RateLimiter::hit($key, 3600);
        }

        return to_route('help.ticket.sent', $ticket->reference());
    }

    public function sent(Request $request, string $reference): Response
    {
        $ticket = $this->find($request, $reference);

        return Inertia::render('Help/TicketSent', ['ticket' => $this->summary($ticket), 'email' => $ticket->reply_email]);
    }

    public function index(Request $request): Response
    {
        $user = $request->user() ?? abort(403);
        $owner = $this->isOwner();
        $filters = [
            'status' => in_array($request->query('status'), [...array_slice(TicketStatus::CLIENT, 0, 3), 'done'], true) ? (string) $request->query('status') : null,
            'category' => in_array($request->query('category'), SupportTicket::CATEGORIES, true) ? (string) $request->query('category') : null,
            'urgency' => in_array($request->query('urgency'), SupportTicket::URGENCIES, true) ? (string) $request->query('urgency') : null,
            'attention' => $request->boolean('attention') ? '1' : null,
            'scope' => $owner && $request->query('scope') === 'all' ? 'all' : null,
            'q' => mb_substr(trim($request->string('q')->toString()), 0, 100) ?: null,
        ];
        $sort = $request->query('sort') === 'created' ? 'created' : 'updated';
        $direction = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $query = SupportTicket::query()->visibleTo($user->id, $filters['scope'] === 'all')
            ->when($filters['status'] === 'done', fn (Builder $q) => $q->whereIn('status', [TicketStatus::RESOLVED, TicketStatus::CLOSED]))
            ->when($filters['status'] !== null && $filters['status'] !== 'done', fn (Builder $q) => $q->whereIn('status', TicketStatus::staffStatesFor((string) $filters['status'])))
            ->when($filters['category'], fn (Builder $q, string $category) => $q->where('category', $category))
            ->when($filters['urgency'], fn (Builder $q, string $urgency) => $q->where('urgency', $urgency))
            ->when($filters['attention'] !== null, fn (Builder $q) => $q->where('user_id', $user->id)->where('status', '!=', TicketStatus::CLOSED)->where(
                fn (Builder $inner) => $inner->where('status', TicketStatus::WAITING_ON_YOU)->orWhere(
                    fn (Builder $unread) => $unread->whereNotNull('last_staff_message_at')->where(fn (Builder $read) => $read->whereNull('client_read_at')->orWhereColumn('client_read_at', '<', 'last_staff_message_at')),
                ),
            ))
            ->when($filters['q'], fn (Builder $q, string $text) => $this->search($q, $text))
            ->orderBy($sort === 'created' ? 'created_at' : 'last_message_at', $direction)->orderBy('reference_number', 'desc');

        $page = $query->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Help/Tickets', [
            'tickets' => $this->rows($page, $user->id),
            'pagination' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'filters' => (object) array_filter($filters),
            'sort' => $sort,
            'dir' => $direction,
            'options' => ['categories' => SupportTicket::CATEGORIES, 'urgencies' => SupportTicket::URGENCIES],
            'canSeeAll' => $owner,
            'hasAny' => SupportTicket::query()->visibleTo($user->id, $owner)->exists(),
        ]);
    }

    public function show(Request $request, string $reference): Response
    {
        $ticket = $this->find($request, $reference);
        $userId = (string) $request->user()?->id;
        $mine = $ticket->user_id === $userId;

        // Seul l'auteur « lit » la réponse : le propriétaire qui consulte le ticket d'un collègue ne la marque pas comme lue.
        if ($mine && $ticket->needsAttention()) {
            $this->desk->markRead($ticket);
        }

        $messages = SupportMessage::query()->where('ticket_id', $ticket->id)->with('attachments')->orderBy('created_at')->orderBy('id')->get();
        $events = SupportTicketEvent::query()->where('ticket_id', $ticket->id)->whereIn('action', ['status_changed', 'reopened', 'closed'])->orderBy('created_at')->get();

        $items = [
            ...$messages->map(fn (SupportMessage $message): array => [
                'type' => 'message',
                'id' => $message->id,
                'from' => $message->author_type === 'staff' ? 'staff' : ($message->author_id === $userId ? 'you' : 'colleague'),
                'body' => $message->body,
                'at' => $message->created_at->toIso8601String(),
                'attachments' => $message->attachments->map(fn (SupportAttachment $file): array => ['id' => $file->id, 'name' => $file->original_name, 'size' => $file->size, 'mime' => $file->mime])->all(),
            ])->all(),
            ...$events->map(fn (SupportTicketEvent $event): array => [
                'type' => 'event', 'id' => $event->id, 'action' => $event->action, 'to' => $event->to_status === null ? null : TicketStatus::forClient($event->to_status), 'reason' => $event->note, 'at' => $event->created_at->toIso8601String(),
            ])->all(),
        ];
        usort($items, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return Inertia::render('Help/Ticket', [
            'ticket' => [...$this->summary($ticket), 'reply_email' => $ticket->reply_email, 'mine' => $mine],
            'thread' => $items,
            'can' => [
                'reply' => TicketStatus::canClientReply($ticket),
                'close' => TicketStatus::canClientClose($ticket),
                'solved' => TicketStatus::canConfirmSolved($ticket),
                'reopen' => TicketStatus::canReopen($ticket),
            ],
            'limits' => ['files' => AttachmentStore::MAX_FILES, 'kilobytes' => AttachmentStore::MAX_KILOBYTES, 'types' => ['PNG', 'JPG', 'PDF']],
            // Invitation à donner son avis (jamais exigée) : après une réponse de l'équipe, ou ticket résolu ou fermé ; à l'auteur seulement.
            'feedback_prompt' => $request->user() === null ? null : $this->feedback->promptFor($request->user(), $ticket),
        ]);
    }

    public function reply(ReplyRequest $request, string $reference): RedirectResponse
    {
        $ticket = $this->find($request, $reference);

        /** @var list<UploadedFile> $files */
        $files = array_values($request->file('attachments', []));

        try {
            $this->desk->clientReply($ticket, $request->user() ?? abort(403), $request->string('body')->toString(), $files);
        } catch (TicketRefused) {
            return back()->withErrors(['body' => __('ui.support.closed_notice')]);
        } catch (InvalidArgumentException) {
            return back()->withErrors(['attachments' => __('ui.support.attachment_invalid')]);
        }

        return back()->with('status', __('ui.support.reply.sent'));
    }

    public function close(Request $request, string $reference): RedirectResponse
    {
        return $this->transition($request, $reference, fn (SupportTicket $ticket) => $this->desk->close($ticket, $request->user() ?? abort(403)), 'ui.support.closed_by_you');
    }

    public function solved(Request $request, string $reference): RedirectResponse
    {
        return $this->transition($request, $reference, fn (SupportTicket $ticket) => $this->desk->confirmSolved($ticket, $request->user() ?? abort(403)), 'ui.support.closed_by_you');
    }

    public function reopen(Request $request, string $reference): RedirectResponse
    {
        return $this->transition($request, $reference, fn (SupportTicket $ticket) => $this->desk->reopen($ticket, $request->user() ?? abort(403)), 'ui.support.reopened');
    }

    /** Téléchargement d'une pièce jointe : le droit d'accès au ticket est revérifié, le fichier n'a jamais d'adresse publique. */
    public function attachment(Request $request, string $reference, string $attachment): StreamedResponse
    {
        $ticket = $this->find($request, $reference);
        $file = SupportAttachment::query()->whereKey($attachment)->first() ?? abort(404);

        abort_unless(SupportMessage::query()->where('id', $file->message_id)->where('ticket_id', $ticket->id)->exists(), 404);
        abort_unless(Storage::disk(AttachmentStore::DISK)->exists($file->path), 404);

        return Storage::disk(AttachmentStore::DISK)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * @param  callable(SupportTicket): mixed  $action
     */
    private function transition(Request $request, string $reference, callable $action, string $successKey): RedirectResponse
    {
        $ticket = $this->find($request, $reference);

        try {
            $action($ticket);
        } catch (TicketRefused) {
            return back()->withErrors(['ticket' => __('ui.support.action_refused')]);
        }

        return back()->with('status', __($successKey));
    }

    private function find(Request $request, string $reference): SupportTicket
    {
        $number = SupportTicket::numberFrom($reference) ?? abort(404);

        return SupportTicket::query()->visibleTo((string) $request->user()?->id, $this->isOwner())->where('reference_number', $number)->first() ?? abort(404);
    }

    private function isOwner(): bool
    {
        return $this->current->role() === 'owner';
    }

    /**
     * @param  Builder<SupportTicket>  $query
     * @return Builder<SupportTicket>
     */
    private function search(Builder $query, string $text): Builder
    {
        $like = '%'.addcslashes($text, '\\%_').'%';
        $number = SupportTicket::numberFrom($text);

        return $query->where(function (Builder $inner) use ($like, $number): void {
            $inner->where('subject', 'ilike', $like)
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('support_messages')->whereColumn('support_messages.ticket_id', 'support_tickets.id')->where('support_messages.body', 'ilike', $like));

            if ($number !== null) {
                $inner->orWhere('reference_number', $number);
            }
        });
    }

    /**
     * @param  LengthAwarePaginator<int, SupportTicket>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(LengthAwarePaginator $page, string $userId): array
    {
        $rows = [];

        foreach ($page->items() as $ticket) {
            $rows[] = [...$this->summary($ticket), 'unread' => $ticket->user_id === $userId && $ticket->needsAttention(), 'mine' => $ticket->user_id === $userId];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(SupportTicket $ticket): array
    {
        return [
            'reference' => $ticket->reference(),
            'subject' => $ticket->subject,
            'status' => TicketStatus::forClient($ticket->status),
            'category' => $ticket->category,
            'related_to' => $ticket->related_to,
            'urgency' => $ticket->urgency,
            'created_at' => $ticket->created_at->toIso8601String(),
            'last_message_at' => $ticket->last_message_at->toIso8601String(),
            'closed_reason' => $ticket->closed_reason,
        ];
    }
}
