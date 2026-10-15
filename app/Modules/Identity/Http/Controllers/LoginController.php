<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Exceptions\InvalidCredentials;
use App\Modules\Identity\Exceptions\TooManyAttempts;
use App\Modules\Identity\Http\Requests\LoginRequest;
use App\Modules\Identity\Services\Authenticator;
use App\Modules\Identity\Services\SessionStarter;
use App\Modules\Identity\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class LoginController
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request, Authenticator $authenticator, SessionStarter $session): RedirectResponse
    {
        try {
            $user = $authenticator->attempt($request->string('email')->toString(), $request->string('password')->toString());
        } catch (InvalidCredentials) {
            // Jamais de précision sur l'e-mail ou le mot de passe (flowchart 2A).
            return back()->withInput($request->only('email'))->withErrors(['email' => __('ui.errors.invalid_credentials')]);
        } catch (TooManyAttempts $e) {
            // E3.
            return back()->withInput($request->only('email'))->withErrors(['email' => __('ui.errors.E3', ['minutes' => $e->retryAfterMinutes()])]);
        }

        // Double authentification : le mot de passe ne suffit plus, on demande le code avant d'ouvrir la session.
        if ($user->hasTwoFactor() && $user->isActivated() && app(TwoFactor::class)->enabled()) {
            $request->session()->regenerate();
            $request->session()->put(TwoFactorChallengeController::SESSION_KEY, ['id' => $user->id, 'at' => now()->timestamp]);

            return redirect()->route('two-factor.challenge');
        }

        return $session->start($request, $user);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Audit::record('auth.logout', 'user', actorId: $request->user()?->id);

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
