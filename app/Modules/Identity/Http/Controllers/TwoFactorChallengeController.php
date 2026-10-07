<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\SessionStarter;
use App\Modules\Identity\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Deuxième étape de la connexion pour un compte protégé par la double authentification : code de l'application ou code de secours.
 * Le mot de passe a déjà été vérifié ; l'utilisateur n'est pas encore connecté.
 */
final class TwoFactorChallengeController
{
    public const SESSION_KEY = 'login.two_factor';

    private const VALID_MINUTES = 10;

    public function show(Request $request): Response|RedirectResponse
    {
        return $this->pending($request) === null ? redirect()->route('login') : Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request, TwoFactor $twoFactor, SessionStarter $session): RedirectResponse
    {
        $user = $this->pending($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $key = 'two-factor:'.$user->id;
        $max = (int) config('nova.security.login_max_attempts');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return $this->abandon($request, RateLimiter::availableIn($key));
        }

        if (! $twoFactor->verify($user, $request->string('code')->toString())) {
            $attempts = RateLimiter::hit($key, (int) config('nova.security.login_lock_minutes') * 60);
            Audit::record('auth.two_factor.failed', 'user', actorId: $user->id);

            if ($attempts >= $max) {
                return $this->abandon($request, RateLimiter::availableIn($key));
            }

            return back()->withErrors(['code' => __('ui.security.two_factor_code_invalid')]);
        }

        RateLimiter::clear($key);
        $request->session()->forget(self::SESSION_KEY);

        return $session->start($request, $user);
    }

    private function abandon(Request $request, int $seconds): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('login')->withErrors(['email' => __('ui.errors.E3', ['minutes' => (int) ceil($seconds / 60)])]);
    }

    private function pending(Request $request): ?User
    {
        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || now()->timestamp - (int) ($state['at'] ?? 0) > self::VALID_MINUTES * 60) {
            return null;
        }

        return User::query()->find($state['id'] ?? null);
    }
}
