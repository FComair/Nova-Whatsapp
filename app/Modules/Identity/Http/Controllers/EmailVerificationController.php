<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Exceptions\TooManyAttempts;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Services\PhoneVerification;
use App\Modules\Identity\Services\SendLimiter;
use App\Modules\Identity\Support\PendingVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class EmailVerificationController
{
    /** Page « consultez votre boîte mail ». Accessible avec la session d'activation en cours. */
    public function notice(Request $request): Response|RedirectResponse
    {
        $user = PendingVerification::user($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/VerifyEmail', ['email' => $user->email, 'verified' => $user->hasVerifiedEmail()]);
    }

    /** Clic sur le lien reçu par e-mail (signé, 24 h). Enchaîne avec l'envoi du code téléphonique. */
    public function verify(Request $request, string $user, string $hash, PhoneVerification $phone): RedirectResponse
    {
        $account = User::query()->find($user);

        if ($account === null || ! hash_equals(sha1($account->email), $hash)) {
            return redirect()->route('verification.expired');
        }

        if (! $account->hasVerifiedEmail()) {
            $account->forceFill(['email_verified_at' => now()])->save();
            Audit::record('auth.email.verified', 'user', actorId: $account->id);
        }

        if ($account->phoneStepDone()) {
            return redirect()->route('login')->with('status', __('ui.verify.already_done'));
        }

        PendingVerification::remember($request, $account);

        try {
            $phone->issue($account);
        } catch (TooManyAttempts) {
            // Un code récent existe peut-être déjà : la page de saisie propose le renvoi.
        }

        return redirect()->route('phone.show');
    }

    /** Lien expiré ou invalide (E2). */
    public function expired(): Response
    {
        return Inertia::render('Auth/VerifyEmailExpired');
    }

    /**
     * Renvoi du lien. Réponse identique que l'adresse existe ou non (pas d'énumération de comptes).
     */
    public function resend(Request $request, SendLimiter $limiter): RedirectResponse
    {
        $pending = PendingVerification::user($request);
        $email = $pending !== null ? $pending->email : mb_strtolower(trim($request->string('email')->toString()));
        $user = $pending ?? User::query()->where('email', $email)->first();

        try {
            // La limite s'applique à l'adresse saisie, qu'elle existe ou non.
            $limiter->hit('email', $user !== null ? $user->id : sha1($email.'|'.$request->ip()));

            if ($user !== null && ! $user->hasVerifiedEmail()) {
                $user->notify(new VerifyEmailNotification);
            }
        } catch (TooManyAttempts $e) {
            return back()->withErrors(['email' => __('ui.errors.E2_resend', ['minutes' => $e->retryAfterMinutes()])]);
        }

        return back()->with('status', __('ui.verify.email_resent'));
    }
}
