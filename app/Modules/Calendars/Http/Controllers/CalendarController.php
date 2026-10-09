<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Booking\Support\PlanningAccess;
use App\Modules\Calendars\Http\Requests\StoreCalendarRequest;
use App\Modules\Calendars\Models\ExternalCalendar;
use App\Modules\Calendars\Services\CalendarFeedService;
use App\Modules\Calendars\Services\CalendarSync;
use App\Modules\Calendars\Support\CalendarFetchFailed;
use App\Modules\Calendars\Support\SafeHttp;
use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Models\PractitionerSchedule;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calendriers externes (docs/02 §1) : propriétaire et responsable de site pour les praticiens de leurs sites ; un praticien
 * gère les siens. Réceptionniste : aucun accès (routes réservées). Les adresses et identifiants ne sont jamais renvoyés au navigateur.
 */
final class CalendarController
{
    public const MAX_PER_PRACTITIONER = 5;

    private const KNOWN_ERRORS = ['invalid_url', 'blocked_host', 'redirect', 'timeout', 'too_large', 'invalid_ics', 'http_401', 'http_403', 'http_404', 'http_5xx'];

    public function __construct(
        private readonly CurrentTenant $current,
        private readonly PlanningAccess $access,
        private readonly CalendarSync $sync,
        private readonly CalendarFeedService $feeds,
        private readonly SafeHttp $http,
    ) {}

    public function index(): Response
    {
        $organization = $this->organization();
        $practitioners = $this->manageable()->orderBy('position')->get();
        $calendars = ExternalCalendar::query()->whereIn('practitioner_id', $practitioners->pluck('id'))->orderBy('created_at')->get()->groupBy('practitioner_id');

        return Inertia::render('Calendars/Index', [
            'practitioners' => $practitioners->map(fn (Practitioner $practitioner): array => [
                'id' => $practitioner->id,
                'name' => $practitioner->name,
                'photo_version' => $practitioner->photoVersion(),
                'feed_url' => $this->feeds->url($this->feeds->tokenFor($organization, $practitioner)),
                'calendars' => ($calendars[$practitioner->id] ?? collect())->map(fn (ExternalCalendar $calendar): array => [
                    'id' => $calendar->id,
                    'name' => $calendar->name,
                    'kind' => $calendar->kind,
                    'status' => $calendar->status,
                    'last_synced_at' => $calendar->last_synced_at?->toIso8601String(),
                    'last_error' => $calendar->last_error === null ? null : (in_array($calendar->last_error, self::KNOWN_ERRORS, true) ? $calendar->last_error : 'unknown'),
                    'unsupported_rules' => $calendar->unsupported_rules,
                ])->values()->all(),
            ])->values()->all(),
            'limit' => self::MAX_PER_PRACTITIONER,
        ]);
    }

    public function store(StoreCalendarRequest $request): RedirectResponse
    {
        $practitioner = $this->manageable()->whereKey($request->string('practitioner_id')->toString())->first() ?? abort(403);

        if (ExternalCalendar::query()->where('practitioner_id', $practitioner->id)->count() >= self::MAX_PER_PRACTITIONER) {
            return back()->withErrors(['url' => __('ui.calendars.errors.limit', ['max' => self::MAX_PER_PRACTITIONER])]);
        }

        $url = $this->http->normalize($request->string('url')->toString());

        try {
            $this->http->validate($url);
        } catch (CalendarFetchFailed $e) {
            return back()->withErrors(['url' => $this->errorText($e->errorCode)]);
        }

        $calendar = ExternalCalendar::query()->create([
            'org_id' => $this->organization()->id,
            'practitioner_id' => $practitioner->id,
            'name' => trim($request->string('name')->toString()),
            'kind' => $request->string('kind')->toString(),
            'url_enc' => $url,
            'username_enc' => $request->filled('username') ? $request->string('username')->toString() : null,
            'password_enc' => $request->filled('password') ? $request->string('password')->toString() : null,
            'created_by' => auth()->id(),
        ])->refresh();

        // Première lecture tout de suite : un calendrier qui ne se lit pas n'est pas enregistré.
        if (! $this->sync->sync($calendar, $this->organization())) {
            $code = (string) $calendar->refresh()->last_error;
            $calendar->delete();

            return back()->withErrors(['url' => $this->errorText($code)]);
        }

        Audit::record('calendar.connected', 'user', (string) auth()->id(), $this->organization()->id, 'external_calendars', $calendar->id, ['kind' => $calendar->kind, 'practitioner_id' => $practitioner->id]);

        return back()->with('status', __('ui.calendars.connected'));
    }

    public function refresh(ExternalCalendar $calendar): RedirectResponse
    {
        $this->authorizeCalendar($calendar);
        $key = 'calendar-sync:'.$calendar->id;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->withErrors(['calendar' => __('ui.calendars.errors.too_soon')]);
        }

        RateLimiter::hit($key, 60);

        return $this->sync->sync($calendar, $this->organization())
            ? back()->with('status', __('ui.calendars.synced'))
            : back()->withErrors(['calendar' => $this->errorText((string) $calendar->refresh()->last_error)]);
    }

    public function toggle(ExternalCalendar $calendar): RedirectResponse
    {
        $this->authorizeCalendar($calendar);

        $calendar->forceFill(['status' => $calendar->status === 'paused' ? 'active' : 'paused', 'failure_count' => 0, 'last_synced_at' => null])->save();

        return back()->with('status', __($calendar->status === 'paused' ? 'ui.calendars.paused' : 'ui.calendars.resumed'));
    }

    public function destroy(ExternalCalendar $calendar): RedirectResponse
    {
        $this->authorizeCalendar($calendar);

        Audit::record('calendar.disconnected', 'user', (string) auth()->id(), $this->organization()->id, 'external_calendars', $calendar->id);
        $calendar->delete();

        return back()->with('status', __('ui.calendars.removed'));
    }

    public function rotateFeed(Request $request, Practitioner $practitioner): RedirectResponse
    {
        abort_unless($this->manageable()->whereKey($practitioner->id)->exists(), 403);

        $this->feeds->rotate($this->organization(), $practitioner, (string) auth()->id());

        return back()->with('status', __('ui.calendars.feed_rotated'));
    }

    /**
     * Praticiens dont l'utilisateur gère les calendriers : le sien pour un praticien, ceux de ses sites pour un responsable de site.
     *
     * @return Builder<Practitioner>
     */
    private function manageable(): Builder
    {
        $query = Practitioner::query()->where('status', Practitioner::STATUS_ACTIVE);

        if ($this->access->isPractitioner()) {
            return $query->where('practitioners.id', $this->access->practitionerId());
        }

        $locationIds = $this->access->locations()->pluck('id');

        return $query->whereIn('practitioners.id', PractitionerSchedule::query()->whereIn('location_id', $locationIds)->select('practitioner_id'));
    }

    private function authorizeCalendar(ExternalCalendar $calendar): void
    {
        abort_unless($this->manageable()->whereKey($calendar->practitioner_id)->exists(), 403);
    }

    private function errorText(string $code): string
    {
        return __('ui.calendars.errors.'.(in_array($code, self::KNOWN_ERRORS, true) ? $code : 'unknown'));
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }
}
