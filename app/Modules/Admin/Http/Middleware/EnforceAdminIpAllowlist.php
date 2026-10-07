<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\PlatformAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Liste d'adresses IP autorisées pour un Super Admin (docs/02 §3, colonne `platform_admins.ip_allowlist`). Vide : aucune restriction.
 * Adresses seules ou plages CIDR (IPv4 et IPv6). Hors liste : session fermée, accès refusé et tentative tracée.
 */
final class EnforceAdminIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();

        if ($admin instanceof PlatformAdmin) {
            $allowed = array_filter((array) $admin->ip_allowlist);

            if ($allowed !== [] && ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
                PlatformAudit::record($admin->id, 'platform.ip_denied');
                Auth::guard('platform')->logout();
                $request->session()->invalidate();

                abort(403);
            }
        }

        return $next($request);
    }
}
