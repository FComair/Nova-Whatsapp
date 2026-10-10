<?php

declare(strict_types=1);

namespace App\Modules\Legal\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Legal\Services\LegalDocuments;
use App\Modules\Legal\Support\LegalDocument;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Réglages › Juridique (docs/28), pour tous les rôles : documents en vigueur, version et date d'acceptation de la personne connectée,
 * historique, et — seulement quand une acceptation manque ou qu'une version significative l'exige — le moyen de l'accepter.
 * Visiter la page n'oblige à rien.
 */
final class LegalSettingsController
{
    public function __construct(private readonly LegalDocuments $documents, private readonly CurrentTenant $tenant) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('Account/Legal', [
            'documents' => $this->documents->statuses($user, $this->owner(), app()->getLocale()),
        ]);
    }

    public function accept(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['string', Rule::in(LegalDocument::slugs())],
        ], ['documents.required' => __('ui.legal.settings.choose'), 'documents.min' => __('ui.legal.settings.choose')]);

        $owner = $this->owner();
        $states = collect($this->documents->statuses($user, $owner, app()->getLocale()))->keyBy('slug');
        $recorded = 0;

        foreach ($data['documents'] as $slug) {
            $state = $states[$slug]['state'] ?? null;

            // Seuls les documents réellement à accepter s'enregistrent ; le contexte dit si c'est une nouvelle acceptation.
            if (! in_array($state, ['missing', 'reacceptance', 'update'], true)) {
                continue;
            }

            $recorded += count($this->documents->accept(
                $user,
                [(string) LegalDocument::code($slug)],
                $state === 'reacceptance' ? LegalDocument::REACCEPTANCE : LegalDocument::SETTINGS,
                $owner,
                $request->ip(),
                $request->userAgent(),
                app()->getLocale(),
            ));
        }

        return to_route('legal.settings')->with('status', $recorded > 0 ? __('ui.legal.settings.accepted') : __('ui.legal.settings.nothing'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }

    private function owner(): bool
    {
        return $this->tenant->role() === OrgMember::ROLE_OWNER;
    }
}
