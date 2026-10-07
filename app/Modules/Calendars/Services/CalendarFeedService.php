<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Services;

use App\Modules\Audit\Audit;
use App\Modules\Calendars\Models\CalendarFeed;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Tenancy\Models\Organization;
use Illuminate\Support\Str;

/**
 * Flux ICS sortant d'un praticien : adresse secrète (jeton) que ses autres outils peuvent lire.
 * Le jeton est renouvelable : l'ancienne adresse cesse aussitôt de fonctionner.
 */
final class CalendarFeedService
{
    public function tokenFor(Organization $organization, Practitioner $practitioner): string
    {
        $feed = CalendarFeed::query()->where('practitioner_id', $practitioner->id)->first();

        return $feed === null ? $this->rotate($organization, $practitioner) : $feed->token_enc;
    }

    public function rotate(Organization $organization, Practitioner $practitioner, ?string $actorId = null): string
    {
        $token = 'nf_'.Str::random(40);

        CalendarFeed::query()->updateOrCreate(
            ['practitioner_id' => $practitioner->id],
            ['org_id' => $organization->id, 'token_hash' => hash('sha256', $token), 'token_enc' => $token],
        );

        Audit::record('calendar_feed.rotated', $actorId === null ? 'system' : 'user', $actorId, $organization->id, 'practitioners', $practitioner->id);

        return $token;
    }

    public function url(string $token): string
    {
        return url('/ics/'.$token.'.ics');
    }
}
