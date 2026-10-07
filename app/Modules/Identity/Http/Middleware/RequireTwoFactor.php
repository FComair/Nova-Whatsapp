<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Middleware;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\TwoFactor;
use App\Modules\Tenancy\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Double authentification obligatoire (décision 18) pour le propriétaire et les responsables de site, sans délai de grâce :
 * tant qu'elle n'est pas activée, seules la page Sécurité, la langue et la déconnexion restent accessibles.
 */
final class RequireTwoFactor
{
    private const ALLOWED = ['logout', 'locale.update'];

    public function __construct(private readonly TwoFactor $twoFactor, private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->hasTwoFactor() || ! $this->twoFactor->isRequiredFor($this->current->role())) {
            return $next($request);
        }

        $route = (string) $request->route()?->getName();

        if (in_array($route, self::ALLOWED, true) || str_starts_with($route, 'security.')) {
            return $next($request);
        }

        return redirect()->route('security.show')->with('warning', __('ui.security.two_factor_required'));
    }
}
