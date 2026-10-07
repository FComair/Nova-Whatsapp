<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restreint une route à certains rôles de l'organisation (docs/02 §1). Usage : `role:owner` ou `role:owner,site_manager`.
 */
final class EnsureRole
{
    public function __construct(private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! in_array($this->current->role(), $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}
