<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Modules\Onboarding\Services\SetupChecklist;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Support\TicketStatus;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Whatsapp\Models\WhatsappAccount;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Données partagées avec toutes les pages. Aucune donnée sensible : ni hachage, ni jeton.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'locale' => $locale,
            'locales' => config('nova.locales'),
            // Groupe de traductions « ui » (lang/{locale}/ui.php) fourni tel quel au front ; les e-mails restent côté serveur.
            'translations' => fn (): array => app('translator')->getLoader()->load($locale, 'ui'),
            // Évalué au rendu de la page, DANS le contexte d'organisation : share() est appelé avant les middlewares de route,
            // à un moment où l'organisation courante n'est pas encore posée.
            'auth' => function () use ($request): array {
                $current = app(CurrentTenant::class);
                $organization = $current->organization();

                return [
                    'user' => $request->user() === null ? null : [
                        'id' => $request->user()->id,
                        'email' => $request->user()->email,
                        'locale' => $request->user()->locale,
                    ],
                    'organization' => $organization === null ? null : [
                        'id' => $organization->id,
                        'name' => $organization->name,
                        'status' => $organization->status,
                        'logo_version' => $organization->logo_updated_at?->getTimestamp(),
                        'role' => $current->role(),
                    ],
                ];
            },
            // Pastilles du menu Réglages (WhatsApp connecté, double authentification) : calculées seulement sur ces pages,
            // dans le contexte d'organisation comme « auth ». Aucune donnée sensible.
            'settings' => function () use ($request): ?array {
                $current = app(CurrentTenant::class);

                if ($request->user() === null || $current->organization() === null || ! $request->is('reglages/*', 'calendriers*', 'whatsapp*', 'compte/donnees*', 'compte/pause*', 'compte/suppression*', 'securite*')) {
                    return null;
                }

                return [
                    'whatsapp_connected' => $current->role() === 'owner' && WhatsappAccount::query()->where('status', 'active')->exists(),
                    'two_factor' => $request->user()->hasTwoFactor(),
                    // Reste à faire par catégorie de « Démarrage » (propriétaire seulement), pour les pastilles du menu.
                    'setup' => $current->role() === 'owner'
                        ? array_map(fn (array $category): array => ['key' => $category['key'], 'left' => $category['left'], 'complete' => $category['complete']], app(SetupChecklist::class)->categories($request->user()))
                        : null,
                ];
            },
            // Pastille du menu Aide : tickets de la personne qui attendent son attention (réponse non lue ou « En attente de vous »).
            // Calculée au rendu, dans le contexte d'organisation, comme « auth ».
            'support' => function () use ($request): ?array {
                if ($request->user() === null || app(CurrentTenant::class)->organization() === null) {
                    return null;
                }

                return ['attention' => SupportTicket::query()->where('user_id', $request->user()->id)->where('status', '!=', TicketStatus::CLOSED)
                    ->where(fn ($query) => $query->where('status', TicketStatus::WAITING_ON_YOU)->orWhere(
                        fn ($unread) => $unread->whereNotNull('last_staff_message_at')->where(fn ($read) => $read->whereNull('client_read_at')->orWhereColumn('client_read_at', '<', 'last_staff_message_at')),
                    ))->count()];
            },
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'warning' => fn () => $request->session()->get('warning'),
                'suggestions' => fn () => $request->session()->get('suggestions', []),
                'first_login' => fn () => (bool) $request->session()->get('first_login'),
            ],
        ];
    }
}
