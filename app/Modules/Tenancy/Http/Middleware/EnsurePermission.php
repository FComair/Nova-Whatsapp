<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restreint une route aux membres qui ont au moins une des capacités données (docs/34). Usage : `permission:settings.billing` ou
 * `permission:calendars.own,settings.integrations`. Le refus est toujours côté serveur : masquer un bouton ne protège rien.
 */
final class EnsurePermission
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        if (! $this->current->canAny(...$capabilities)) {
            abort(403);
        }

        return $next($request);
    }
}
