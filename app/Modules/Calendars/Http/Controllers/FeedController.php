<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Http\Controllers;

use App\Modules\Calendars\Services\IcsFeedBuilder;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Flux ICS sortant : adresse secrète, sans session. Le jeton donne accès à UN praticien ; jeton inconnu ou renouvelé : 404.
 */
final class FeedController
{
    public function __construct(private readonly TenantContext $tenants, private readonly IcsFeedBuilder $builder) {}

    public function show(string $token): Response
    {
        $row = DB::selectOne('select * from calendar_feed_lookup(?)', [hash('sha256', $token)]);

        abort_if($row === null, 404);

        $ics = $this->tenants->run((string) $row->org_id, function () use ($row): ?string {
            $organization = Organization::query()->find($row->org_id);
            $practitioner = Practitioner::query()->find($row->practitioner_id);

            if ($organization === null || $practitioner === null || ! in_array($organization->status, [Organization::STATUS_TRIAL, Organization::STATUS_ACTIVE, Organization::STATUS_PAST_DUE, Organization::STATUS_SUSPENDED], true)) {
                return null;
            }

            return $this->builder->build($organization, $practitioner);
        });

        abort_if($ics === null, 404);

        return new Response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
