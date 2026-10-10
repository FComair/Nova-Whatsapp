<?php

declare(strict_types=1);

namespace App\Modules\Legal\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Legal\Services\LegalDocuments;
use App\Modules\Tenancy\Models\OrgMember;
use App\Modules\Tenancy\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nouvelle acceptation d'un document juridique (docs/28) : quand une version juridiquement significative est publiée et que l'utilisateur
 * avait accepté une version antérieure, l'usage du service attend son acceptation explicite. Restent accessibles : la page Juridique et
 * les documents, la langue, la déconnexion, la sécurité du compte, l'aide, la facturation et « Mes données » (export, suppression) : on ne retient jamais
 * quelqu'un loin de ses droits ni de la possibilité de partir.
 * Un utilisateur sans aucune acceptation enregistrée (invité avant cette fonction) reçoit seulement un avis, jamais un blocage.
 */
final class RequireLegalReacceptance
{
    private const ALLOWED_PREFIXES = ['legal.', 'security.', 'help.', 'billing.', 'account.', 'settings.profile.'];

    private const ALLOWED = ['logout', 'locale.update'];

    public function __construct(private readonly LegalDocuments $documents, private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $route = (string) $request->route()?->getName();

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return $next($request);
            }
        }

        if (in_array($route, self::ALLOWED, true)) {
            return $next($request);
        }

        if (! $this->documents->blocked($user, $this->current->role() === OrgMember::ROLE_OWNER, app()->getLocale())) {
            return $next($request);
        }

        return redirect()->route('legal.settings')->with('warning', __('ui.legal.settings.blocked'));
    }
}
