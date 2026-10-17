<?php

declare(strict_types=1);

namespace App\Modules\Feedback\Http\Controllers;

use App\Modules\Feedback\Http\Requests\FeedbackRequest;
use App\Modules\Feedback\Services\FeedbackService;
use App\Modules\Feedback\Services\ReviewLinks;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Avis des clients sur Rendivoo (docs/26) : formulaire privé à l'équipe (à tout moment ou à propos d'un ticket), invitation « Pas
 * maintenant », et liens d'avis publics facultatifs. Tous les rôles, comme le reste de l'aide ; rien n'est exigé pour continuer.
 */
final class FeedbackController
{
    public function __construct(
        private readonly CurrentTenant $current,
        private readonly FeedbackService $feedback,
        private readonly ReviewLinks $links,
    ) {}

    public function create(Request $request): Response
    {
        $ticket = $this->ticket($request, $request->query('ticket'));
        $sent = $request->session()->get('feedback_sent');

        return Inertia::render('Help/Feedback', [
            'ticket' => $ticket === null ? null : ['reference' => $ticket->reference(), 'subject' => $ticket->subject],
            'already_sent' => $ticket !== null && $this->feedback->hasFeedbackFor($request->user() ?? abort(403), $ticket),
            'sent' => is_string($sent) ? $sent : null,
            'links' => $this->links->available(),
            'limits' => ['comment' => FeedbackService::COMMENT_MAX],
        ]);
    }

    public function store(FeedbackRequest $request): RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        $ticket = $this->ticket($request, $request->input('ticket'));

        try {
            $feedback = $this->feedback->submit($user, $this->organization(), $request->integer('rating'), $request->input('comment'), $ticket);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['ticket' => __('ui.feedback.errors.already_sent')]);
        }

        return to_route('help.reviews', $ticket === null ? [] : ['ticket' => $ticket->reference()])->with('feedback_sent', $feedback->id);
    }

    /** « Pas maintenant » : on ne repropose pas l'invitation de ce moment. */
    public function dismiss(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'regex:/^(ticket|intervention):[0-9a-f-]{36}$/']]);
        $user = $request->user() ?? abort(403);
        [, $ticketId] = explode(':', $data['key']);

        abort_unless(SupportTicket::query()->visibleTo($user->id, $this->isOwner())->whereKey($ticketId)->exists(), 404);
        $this->feedback->dismiss($user, $this->organization(), $data['key']);

        return back();
    }

    /**
     * Lien d'avis public : on note que la personne l'ouvre (la plateforme seulement), puis elle est envoyée sur Google ou Trustpilot,
     * où elle écrit elle-même son avis. Rien n'y est publié par Rendivoo ; aucun lien n'existe sans adresse configurée.
     */
    public function link(Request $request, string $platform): RedirectResponse
    {
        $url = $this->links->url($platform) ?? abort(404);

        $this->feedback->recordClick($request->user() ?? abort(403), $this->organization(), $platform, $request->query('feedback') === null ? null : (string) $request->query('feedback'));

        return redirect()->away($url);
    }

    /** Ticket de l'URL ou du formulaire : visible par cette personne (les siens ; ceux de l'établissement pour le propriétaire). */
    private function ticket(Request $request, mixed $reference): ?SupportTicket
    {
        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $number = SupportTicket::numberFrom($reference) ?? abort(404);

        return SupportTicket::query()->visibleTo((string) $request->user()?->id, $this->isOwner())->where('reference_number', $number)->first() ?? abort(404);
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }

    private function isOwner(): bool
    {
        return $this->current->role() === 'owner';
    }
}
