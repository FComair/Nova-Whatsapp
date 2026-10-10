<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Services\PlatformAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Déconnecte le Super Admin après une période d'inactivité (RGPD art. 32 : les sessions à privilèges ne restent pas ouvertes).
 *
 * La durée vient de `nova.security.admin_idle_minutes` (60 par défaut, 0 = désactivé) ; elle est plus courte que la durée de session
 * générale (`SESSION_LIFETIME`). Chaque requête repousse l'échéance ; une requête Livewire expirée reçoit « page expirée » (419), ce qui
 * renvoie l'agent vers la connexion.
 */
final class EnforceAdminIdleTimeout
{
    private const KEY = 'admin_last_activity';

    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('nova.security.admin_idle_minutes', 60);
        $guard = Auth::guard('platform');

        if ($limit > 0 && $guard->check()) {
            $last = (int) $request->session()->get(self::KEY, 0);

            if ($last > 0 && now()->timestamp - $last > $limit * 60) {
                $adminId = (string) $guard->id();
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                PlatformAudit::record($adminId, 'platform.session_expired', null, 'platform_admin', $adminId, ['idle_minutes' => $limit]);

                return $request->hasHeader('X-Livewire') ? response('', 419) : redirect()->guest(route('filament.admin.auth.login'));
            }

            $request->session()->put(self::KEY, now()->timestamp);
        }

        return $next($request);
    }
}
