<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue de l'interface (docs/03 §A.5) : préférence du compte > choix en session > en-tête Accept-Language > défaut.
 */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('nova.locales');

        $candidates = [
            $request->user()?->locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->getPreferredLanguage($supported),
            config('app.locale'),
        ];

        $locale = collect($candidates)->first(fn ($value): bool => is_string($value) && in_array($value, $supported, true)) ?? 'fr';

        app()->setLocale($locale);

        return $next($request);
    }
}
