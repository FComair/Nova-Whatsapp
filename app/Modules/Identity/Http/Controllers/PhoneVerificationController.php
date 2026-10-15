<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Exceptions\TooManyAttempts;
use App\Modules\Identity\Services\PhoneVerification;
use App\Modules\Identity\Services\SessionStarter;
use App\Modules\Identity\Support\PendingVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Étape « code à 6 chiffres envoyé au téléphone professionnel » (flowchart 1A), puis activation du compte.
 */
final class PhoneVerificationController
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = PendingVerification::user($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        if (! $user->emailStepDone()) {
            return redirect()->route('verification.notice');
        }

        // Vérification du téléphone coupée (Paramètres globaux) : rien à saisir, le compte peut se connecter.
        if ($user->phoneStepDone()) {
            return redirect()->route('login')->with('status', __('ui.verify.already_done'));
        }

        return Inertia::render('Auth/VerifyPhone', [
            'phoneMasked' => $this->mask((string) $user->phone_e164),
            'codeLength' => (int) config('nova.security.phone_code_length'),
        ]);
    }

    public function verify(Request $request, PhoneVerification $phone, SessionStarter $session): RedirectResponse
    {
        $user = PendingVerification::user($request);

        if ($user === null || ! $user->emailStepDone()) {
            return redirect()->route('login');
        }

        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{'.(int) config('nova.security.phone_code_length').'}$/']]);

        try {
            $result = $phone->verify($user, $data['code']);
        } catch (TooManyAttempts $e) {
            return back()->withErrors(['code' => __('ui.errors.E2_blocked', ['minutes' => $e->retryAfterMinutes()])]);
        }

        if ($result !== PhoneVerification::VERIFIED) {
            // E2 : « Code incorrect » ou « Ce lien a expiré » (le code a une durée de vie limitée).
            return back()->withErrors(['code' => $result === PhoneVerification::EXPIRED ? __('ui.errors.E2_expired') : __('ui.errors.E2_code')]);
        }

        Audit::record('auth.phone.verified', 'user', actorId: $user->id);

        // Compte activé : connexion automatique (flowchart 1A).
        return $session->start($request, $user)->with('first_login', true);
    }

    public function resend(Request $request, PhoneVerification $phone): RedirectResponse
    {
        $user = PendingVerification::user($request);

        if ($user === null || ! $user->emailStepDone()) {
            return redirect()->route('login');
        }

        try {
            $phone->issue($user);
        } catch (TooManyAttempts $e) {
            return back()->withErrors(['code' => __('ui.errors.E2_resend', ['minutes' => $e->retryAfterMinutes()])]);
        }

        return back()->with('status', __('ui.verify.code_resent'));
    }

    private function mask(string $phone): string
    {
        return strlen($phone) > 6 ? substr($phone, 0, 4).str_repeat('•', max(0, strlen($phone) - 6)).substr($phone, -2) : $phone;
    }
}
