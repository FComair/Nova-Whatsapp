<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Services;

use App\Modules\Booking\Services\PolicyService;
use App\Modules\Calendars\Models\ExternalBusySlot;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Calendars\Support\CalendarFetcher;
use App\Modules\Calendars\Support\CalendarFetchFailed;
use App\Modules\Calendars\Support\IcsEvent;
use App\Modules\Calendars\Support\IcsParser;
use App\Modules\Calendars\Support\RecurrenceExpander;
use App\Modules\Tenancy\Models\Location;
use App\Modules\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Synchronisation LECTURE SEULE d'un calendrier externe vers les plages occupées du praticien.
 *
 * - Période lue : de la veille jusqu'à l'horizon de réservation (politiques) + 7 jours.
 * - Seuls des horaires sont conservés (aucun titre, lieu ni participant).
 * - Les événements issus du flux sortant de Nova sont ignorés (pas de double comptage si le calendrier réimporte notre flux).
 * - Échec : les dernières plages connues restent utilisées (on bloque plutôt que de risquer un double-rendez-vous) ;
 *   après 5 échecs consécutifs le calendrier passe en « erreur » et n'est plus réessayé qu'une fois par heure.
 */
final class CalendarSync
{
    public const FEED_UID_SUFFIX = '@feed.nova';

    public const MAX_SLOTS = 5000;

    public const ERROR_AFTER_FAILURES = 5;

    public function __construct(
        private readonly CalendarFetcher $fetcher,
        private readonly IcsParser $parser,
        private readonly RecurrenceExpander $expander,
        private readonly PolicyService $policies,
    ) {}

    /**
     * À exécuter sous le contexte de l'organisation.
     *
     * @return bool vrai si la synchronisation a réussi
     */
    public function sync(ExternalCalendar $calendar, Organization $organization): bool
    {
        $now = CarbonImmutable::now();
        $from = $now->subDay()->getTimestamp();
        $to = $now->addDays(max(30, $this->policies->for($organization)->max_horizon_days) + 7)->getTimestamp();

        try {
            $ics = $this->fetcher->fetch($calendar, $from, $to);
            $events = array_values(array_filter(
                $this->parser->events($ics, $this->timezoneOf($calendar)),
                fn (IcsEvent $event): bool => ! str_ends_with($event->uid, self::FEED_UID_SUFFIX),
            ));
            $busy = array_slice($this->expander->busy($events, $from, $to), 0, self::MAX_SLOTS);
        } catch (CalendarFetchFailed $e) {
            $this->fail($calendar, $e->errorCode);

            return false;
        }

        DB::transaction(function () use ($calendar, $busy, $now): void {
            ExternalBusySlot::query()->where('calendar_id', $calendar->id)->delete();

            foreach (array_chunk($busy, 500) as $chunk) {
                ExternalBusySlot::query()->insert(array_map(fn (array $range): array => [
                    'id' => (string) Str::uuid7(),
                    'org_id' => $calendar->org_id,
                    'calendar_id' => $calendar->id,
                    'starts_at' => CarbonImmutable::createFromTimestampUTC($range[0]),
                    'ends_at' => CarbonImmutable::createFromTimestampUTC($range[1]),
                ], $chunk));
            }

            $calendar->forceFill([
                'status' => 'active',
                'last_synced_at' => $now,
                'failure_count' => 0,
                'last_error' => null,
                'unsupported_rules' => $this->expander->unsupported,
            ])->save();
        });

        return true;
    }

    /**
     * Calendriers à synchroniser maintenant : jamais synchronisés, ou dont l'intervalle est écoulé
     * (`nova.calendars.sync_minutes`, une heure pour ceux en erreur). Les calendriers en pause sont ignorés.
     *
     * @return Collection<int, ExternalCalendar>
     */
    public function due(): Collection
    {
        $regular = CarbonImmutable::now()->subMinutes((int) config('nova.calendars.sync_minutes', 15));
        $errored = CarbonImmutable::now()->subMinutes((int) config('nova.calendars.error_retry_minutes', 60));

        return ExternalCalendar::query()->where('status', '!=', 'paused')->where(function ($query) use ($regular, $errored): void {
            $query->whereNull('last_synced_at')
                ->orWhere(fn ($q) => $q->where('status', 'active')->where('last_synced_at', '<=', $regular))
                ->orWhere(fn ($q) => $q->where('status', 'error')->where('last_synced_at', '<=', $errored));
        })->orderBy('last_synced_at')->limit(50)->get();
    }

    private function fail(ExternalCalendar $calendar, string $code): void
    {
        $failures = (int) $calendar->failure_count + 1;

        $calendar->forceFill([
            'failure_count' => $failures,
            'last_error' => $code,
            'last_synced_at' => now(),
            'status' => $failures >= self::ERROR_AFTER_FAILURES ? 'error' : ($calendar->status ?? 'active'),
        ])->save();
    }

    /** Fuseau des événements « flottants » ou en journée entière : celui du site du praticien. */
    private function timezoneOf(ExternalCalendar $calendar): string
    {
        $timezone = DB::table('practitioner_schedules')->where('practitioner_id', $calendar->practitioner_id)->value('location_id');

        return (string) (Location::query()->whereKey($timezone)->value('timezone') ?? Location::query()->orderBy('created_at')->value('timezone') ?? 'UTC');
    }
}
