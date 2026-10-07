<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applique les effets du statut de l'organisation sur le dashboard (docs/02 §4, flowcharts 2A/4A/4B/4D).
 *
 * - `suspended` : lecture seule. Toute écriture est refusée ; la lecture reste possible.
 * - `paused` et `pending_deletion` : uniquement l'écran d'état, l'export et le compte (réactivation, annulation).
 * - `deleted` : accès impossible.
 */
final class EnsureOrganizationUsable
{
    /** Routes toujours accessibles, quel que soit le statut (écran d'état, langue, déconnexion, export). */
    private const ALWAYS_ALLOWED = ['account.state', 'logout', 'locale.update', 'account.data', 'account.export', 'account.deletion.request', 'account.deletion.confirm', 'account.deletion.cancel', 'account.pause.show', 'account.pause.resume'];

    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organization = $this->current->organization();

        if ($organization === null) {
            abort(403);
        }

        $route = (string) $request->route()?->getName();

        // La facturation reste accessible à un compte suspendu : c'est ainsi qu'il régularise.
        if (in_array($route, self::ALWAYS_ALLOWED, true) || str_starts_with($route, 'billing.') || str_starts_with($route, 'security.')) {
            return $next($request);
        }

        return match ($organization->status) {
            Organization::STATUS_DELETED => abort(403),
            Organization::STATUS_PAUSED, Organization::STATUS_PENDING_DELETION => redirect()->route('account.state'),
            Organization::STATUS_SUSPENDED => $request->isMethodSafe()
                ? $next($request)
                : redirect()->route('account.state')->withErrors(['account' => __('ui.account.read_only')]),
            default => $next($request),
        };
    }
}
