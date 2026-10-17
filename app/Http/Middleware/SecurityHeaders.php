<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité HTTP sur toutes les réponses.
 *
 * - Toujours : pas de détection de type (nosniff), interdiction d'être affiché dans un cadre, politique de référent stricte,
 *   fonctions du navigateur inutiles désactivées, isolation de la fenêtre.
 * - HSTS : en production, sur les requêtes HTTPS.
 * - CSP (politique de contenu) : `rendivoo.security.csp` = `report-only` (défaut : signale sans bloquer), `enforce` ou `off`. Le panel
 *   Super Admin (Filament/Livewire, qui exige des scripts en ligne) est exclu de la CSP, pas des autres en-têtes.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // Le PDF d'un document juridique s'affiche dans un cadre de SA PROPRE page (docs/35) : seul cet affichage est autorisé, et seulement par l'origine elle-même.
        $response->headers->set('X-Frame-Options', $request->is('legal/*/file') ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        // Sur les pages de connexion WhatsApp, la fenêtre de Meta doit pouvoir renvoyer son résultat à la page qui l'a ouverte.
        $response->headers->set('Cross-Origin-Opener-Policy', $this->allowsMetaSignup($request) ? 'same-origin-allow-popups' : 'same-origin');

        if ($request->isSecure() && app()->isProduction()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $mode = (string) config('rendivoo.security.csp', 'report-only');

        if ($mode !== 'off' && ! $request->is('admin', 'admin/*', 'livewire/*') && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', $this->policy($this->allowsMetaSignup($request)));
        }

        return $response;
    }

    /**
     * Inscription intégrée de Meta : le module et la fenêtre de Meta ne sont autorisés QUE sur les pages de connexion WhatsApp, et seulement
     * quand l'application Meta est configurée. (RGPD : Meta ne reçoit l'adresse IP de l'utilisateur qu'au clic sur « Se connecter avec Meta ».)
     */
    private function allowsMetaSignup(Request $request): bool
    {
        return filled(config('whatsapp.embedded_signup.app_id')) && $request->is('whatsapp', 'configuration/assistant/whatsapp');
    }

    private function policy(bool $meta = false): string
    {
        // Développement : le serveur Vite (rechargement à chaud) sert les scripts et ouvre un canal WebSocket.
        $dev = app()->environment('local') ? ' http://localhost:5173 http://127.0.0.1:5173' : '';
        $devSocket = app()->environment('local') ? ' ws://localhost:5173 ws://127.0.0.1:5173' : '';
        $metaScript = $meta ? ' https://connect.facebook.net' : '';
        $metaConnect = $meta ? ' https://www.facebook.com https://graph.facebook.com' : '';
        $metaFrame = $meta ? "frame-src 'self' https://www.facebook.com https://web.facebook.com" : "frame-src 'self'";

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self'{$dev}{$metaScript}",
            "style-src 'self' 'unsafe-inline'{$dev}",
            "img-src 'self' data:",
            "font-src 'self' data:{$dev}",
            "connect-src 'self'{$dev}{$devSocket}{$metaConnect}",
            $metaFrame,
            "frame-ancestors 'none'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);
    }
}
