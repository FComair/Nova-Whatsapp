<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Models\PlatformAdmin;
use App\Modules\Admin\Services\PlatformAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Connexion directe au Super Admin pour les essais en développement local (voir DevAdminLink). Plusieurs verrous cumulés :
 * environnement `local` uniquement (404 sinon), lien signé et non expiré, jeton à usage unique créé par la commande, adresse locale.
 */
final class DevAdminLoginController
{
    /** Boucle locale et réseaux privés (le conteneur voit l'hôte par l'adresse de la passerelle Docker). */
    private const LOCAL_RANGES = ['127.0.0.0/8', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];

    public function __invoke(Request $request, string $nonce): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless(IpUtils::checkIp((string) $request->ip(), self::LOCAL_RANGES), 403);

        $adminId = Cache::pull('dev-admin-login:'.$nonce);
        $admin = is_string($adminId) ? PlatformAdmin::query()->find($adminId) : null;

        abort_if($admin === null, 403);

        Auth::guard('platform')->login($admin);
        $request->session()->regenerate();
        PlatformAudit::record($admin->id, 'platform.dev_login');

        return redirect('/admin');
    }
}
