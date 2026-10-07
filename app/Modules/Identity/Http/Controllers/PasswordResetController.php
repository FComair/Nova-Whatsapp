<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\PasswordChangedNotification;
use App\Modules\Identity\Rules\StrongPassword;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mot de passe oublié (flowchart 2A) : lien valable 60 min et à usage unique, même réponse si l'e-mail est inconnu.
 */
final class PasswordResetController
{
    public function request(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);

        // Le résultat est volontairement ignoré : la réponse est la même que le compte existe ou non.
        Password::sendResetLink(['email' => mb_strtolower($data['email'])]);

        return back()->with('status', __('ui.forgot.sent'));
    }

    public function reset(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', new StrongPassword(User::query()->where('email', mb_strtolower((string) $request->input('email')))->first()), 'max:200', 'confirmed'],
        ]);

        $status = Password::reset(
            ['email' => mb_strtolower($data['email']), 'password' => $data['password'], 'password_confirmation' => $request->input('password_confirmation'), 'token' => $data['token']],
            function (User $user, string $password): void {
                // Le changement de hash invalide toutes les autres sessions (AuthenticateSession).
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'failed_login_count' => 0,
                    'locked_until' => null,
                ])->save();

                event(new PasswordReset($user));
                Audit::record('auth.password.reset', 'user', actorId: $user->id);
                $user->notify(new PasswordChangedNotification);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Lien expiré, déjà utilisé ou invalide : message unique (E2).
            return back()->withErrors(['email' => __('ui.errors.E2_link')]);
        }

        return redirect()->route('login')->with('status', __('ui.reset.done'));
    }
}
