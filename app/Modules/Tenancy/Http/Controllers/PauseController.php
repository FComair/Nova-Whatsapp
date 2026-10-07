<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Exceptions\PauseRefused;
use App\Modules\Tenancy\Models\Organization;
use App\Modules\Tenancy\Services\AccountPause;
use App\Modules\Tenancy\Support\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pause d'établissement (flowchart 4B), propriétaire uniquement. Accessible aussi quand le compte est en pause, pour la lever.
 */
final class PauseController
{
    public function __construct(private readonly CurrentTenant $current, private readonly AccountPause $pause) {}

    public function show(Request $request): Response
    {
        $organization = $this->organization();
        $days = min(30, $this->pause->maxDays());

        return Inertia::render('Account/Pause', [
            'status' => $organization->status,
            'canPause' => in_array($organization->status, AccountPause::STARTABLE, true),
            'maxDays' => $this->pause->maxDays(),
            'defaultDays' => $days,
            'pauseUntil' => $organization->pause_until?->toIso8601String(),
            'message' => $organization->unavailable_message[$organization->locale] ?? null,
            'upcoming' => $this->pause->upcomingBookings($this->pause->maxDays())->count(),
            'expired' => $organization->status === Organization::STATUS_SUSPENDED && $organization->suspension_reason === AccountPause::EXPIRED_REASON,
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:'.$this->pause->maxDays()],
            'message' => ['nullable', 'string', 'max:500'],
            'password' => ['required', 'string'],
            'confirm' => ['accepted'],
            'cancel_bookings' => ['nullable', 'boolean'],
        ]);

        if (! $this->passwordOk($request)) {
            return back()->withErrors(['password' => __('ui.pause.errors.password')]);
        }

        try {
            $result = $this->pause->start($this->organization(), (int) $data['days'], $data['message'] ?? null, (string) $request->user()?->id, (bool) ($data['cancel_bookings'] ?? false));
        } catch (PauseRefused $e) {
            return back()->withErrors(['pause' => __("ui.pause.errors.{$e->reason}", ['count' => $e->detail, 'max' => $e->detail])]);
        }

        $redirect = redirect()->route('account.state')->with('status', $result['cancelled'] > 0 ? __('ui.pause.started_cancelled', $result) : __('ui.pause.started'));

        // Clients à prévenir autrement : désinscrits, sans numéro WhatsApp joignable ou échec d'envoi.
        return $result['unreachable'] > 0 ? $redirect->with('warning', __('ui.pause.unreachable', ['count' => $result['unreachable']])) : $redirect;
    }

    public function resume(Request $request): RedirectResponse
    {
        try {
            $this->pause->resume($this->organization(), (string) $request->user()?->id);
        } catch (PauseRefused $e) {
            return back()->withErrors(['pause' => __("ui.pause.errors.{$e->reason}")]);
        }

        return redirect()->route('dashboard')->with('status', __('ui.pause.resumed'));
    }

    private function organization(): Organization
    {
        return $this->current->organization() ?? abort(403);
    }

    private function passwordOk(Request $request): bool
    {
        $user = $request->user();
        $key = 'pause-password:'.$user?->id;

        if ($user === null || RateLimiter::tooManyAttempts($key, 5)) {
            return false;
        }

        if (! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($key, 900);

            return false;
        }

        RateLimiter::clear($key);

        return true;
    }
}
