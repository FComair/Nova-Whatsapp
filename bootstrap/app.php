<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Modules\Api\Http\Middleware\AuthenticateApiClient;
use App\Modules\Tenancy\Http\Middleware\EnsurePermission;
use App\Modules\Tenancy\Http\Middleware\EnsureRole;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En-têtes de sécurité sur toutes les réponses ; adresses IP réelles derrière un proxy de confiance (journaux d'audit).
        $middleware->append(SecurityHeaders::class);
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') === '*' ? '*' : (env('TRUSTED_PROXIES') ? array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))) : null));

        $middleware->web(append: [
            SetLocale::class,
            HandleInertiaRequests::class,
        ]);

        $middleware->alias(['role' => EnsureRole::class, 'permission' => EnsurePermission::class, 'api.auth' => AuthenticateApiClient::class]);

        // Le contexte d'organisation doit être posé AVANT la résolution des modèles de route (SubstituteBindings) :
        // sinon la RLS ne voit aucune ligne et tout {location} renverrait 404.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenantContext::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: AuthenticateApiClient::class);

        // Sessions connectées : redirection vers la connexion ; visiteurs déjà connectés vers le tableau de bord.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Lien de vérification e-mail expiré ou falsifié : erreur E2 (« Ce lien a expiré »), jamais un 403 brut.
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if ($request->routeIs('verification.verify')) {
                return redirect()->route('verification.expired');
            }

            return null;
        });
    })->create();
