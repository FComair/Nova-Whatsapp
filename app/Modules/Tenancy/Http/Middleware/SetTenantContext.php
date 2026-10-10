<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Team\Support\Capabilities;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pose l'organisation courante sur la connexion PostgreSQL pour la durée de la requête.
 *
 * L'organisation vient de la session, mais elle est TOUJOURS revérifiée auprès de la base (auth_memberships) :
 * un identifiant trafiqué dans la session ne donne accès à rien.
 * Le contexte est vidé à la fin de la requête, même en cas d'exception.
 */
final class SetTenantContext
{
    public const SESSION_KEY = 'current_org_id';

    public function __construct(private readonly TenantContext $tenant, private readonly CurrentTenant $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $orgId = $request->session()->get(self::SESSION_KEY);

        if ($user === null || ! is_string($orgId)) {
            abort(403);
        }

        $membership = collect(DB::select('select * from auth_memberships(?)', [$user->id]))
            ->firstWhere('org_id', $orgId);

        if ($membership === null) {
            $request->session()->forget(self::SESSION_KEY);
            abort(403);
        }

        $this->tenant->set($orgId);

        try {
            $organization = Organization::query()->find($orgId);

            if ($organization === null) {
                abort(403);
            }

            $permissions = $membership->permissions === null ? null : (array) json_decode((string) $membership->permissions, true);
            $this->current->set($organization, $membership->role, $permissions === null ? null : Capabilities::clean($permissions));

            return $next($request);
        } finally {
            $this->tenant->clear();
            $this->current->clear();
        }
    }
}
