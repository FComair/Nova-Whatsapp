<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\PlatformAudit;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Déconnecte le Super Admin après une période d'inactivité (RGPD art. 32 : les sessions à privilèges ne restent pas ouvertes) et refuse toute session
 * ouverte avant un « déconnecter partout » (`sessions_revoked_at`, docs/38).
 *
 * La durée vient de `nova.security.admin_idle_minutes` (60 par défaut, 0 = désactivé) ; elle est plus courte que la durée de session
 * générale (`SESSION_LIFETIME`). Chaque requête repousse l'échéance ; une requête Livewire expirée reçoit « page expirée » (419), ce qui
 * renvoie l'agent vers la connexion.
 */
final class EnforceAdminIdleTimeout
{
    private const KEY = 'admin_last_activity';

    /** Heure d'ouverture de la session, posée à la connexion (voir RecordAdminAuthentication). */
    public const STARTED_KEY = 'admin_session_started';

    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('nova.security.admin_idle_minutes', 60);
        $guard = Auth::guard('platform');

        if ($guard->check() && $this->revoked($request, $guard->user())) {
            return $this->endSession($request, $guard, 'platform.session_revoked', []);
        }

        if ($limit > 0 && $guard->check()) {
            $last = (int) $request->session()->get(self::KEY, 0);

            if ($last > 0 && now()->timestamp - $last > $limit * 60) {
                return $this->endSession($request, $guard, 'platform.session_expired', ['idle_minutes' => $limit]);
            }

            $request->session()->put(self::KEY, now()->timestamp);
        }

        return $next($request);
    }

    /** Session ouverte avant la dernière déconnexion forcée du compte (ou sans heure d'ouverture connue) : refusée. */
    private function revoked(Request $request, mixed $admin): bool
    {
        $at = $admin instanceof PlatformAdmin ? $admin->sessions_revoked_at : null;

        return $at !== null && (int) $request->session()->get(self::STARTED_KEY, 0) < $at->timestamp;
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function endSession(Request $request, StatefulGuard $guard, string $action, array $detail): Response
    {
        $adminId = (string) $guard->id();
        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        PlatformAudit::record($adminId, $action, null, 'platform_admin', $adminId, $detail === [] ? null : $detail);

        return $request->hasHeader('X-Livewire') ? response('', 419) : redirect()->guest(route('filament.admin.auth.login'));
    }
}
