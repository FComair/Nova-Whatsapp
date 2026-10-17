<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Exceptions\TooManyAttempts;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ConfirmNewEmailNotification;
use App\Modules\Identity\Notifications\EmailChangeNoticeNotification;
use App\Modules\Identity\Notifications\PasswordChangedNotification;
use App\Modules\Identity\Rules\StrongPassword;
use App\Modules\Identity\Services\PhoneChange;
use App\Modules\Identity\Services\TwoFactor;
use App\Modules\Tenancy\Support\CurrentTenant;
use App\Support\Phone;
use App\Support\ValidPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

/**
 * Paramètres > Sécurité (flowchart 3C, décision 18) : mot de passe, e-mail, téléphone, sessions et double authentification.
 *
 * Toute modification sensible redemande le mot de passe actuel (limité à 5 essais par 15 minutes).
 */
final class SecurityController
{
    public function __construct(private readonly TwoFactor $twoFactor, private readonly CurrentTenant $tenant) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $setup = $user->two_factor_secret !== null && ! $user->hasTwoFactor();
        $changedAt = $user->password_changed_at;

        // L'e-mail et le téléphone se modifient dans Réglages > Profil (mêmes routes ci-dessous, même mot de passe actuel).
        return Inertia::render('Account/Security', [
            'passwordChangedAt' => $changedAt?->toIso8601String(),
            'passwordOld' => $changedAt !== null && $changedAt->lt(now()->subMonths((int) config('rendivoo.security.password_max_age_months'))),
            'twoFactor' => [
                'enabled' => $user->hasTwoFactor(),
                'required' => $this->twoFactor->isRequiredFor($this->tenant->role()),
                'setup' => $setup ? ['secret' => $user->two_factor_secret, 'qr' => $this->twoFactor->qrCode($user)] : null,
                'remainingCodes' => $user->hasTwoFactor() ? $this->twoFactor->remainingRecoveryCodes($user) : 0,
            ],
            'recoveryCodes' => $request->session()->get('recovery_codes'),
        ]);
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $this->reauthenticate($request, $user);

        $data = $request->validate([
            'password' => ['required', 'string', new StrongPassword($user), 'max:200', 'confirmed'],
        ]);

        // Nouveau mot de passe (historique et date de changement inclus), puis fermeture de toutes les autres sessions.
        $user->forceFill(['password' => $data['password']])->save();
        $user->passwordRehashOnly = true;
        Auth::logoutOtherDevices($data['password']);
        $user->passwordRehashOnly = false;

        Audit::record('auth.password.changed', 'user', actorId: $user->id);
        $user->notify(new PasswordChangedNotification);

        return back()->with('status', __('ui.security.password_changed'));
    }

    public function closeOtherSessions(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $this->reauthenticate($request, $user);

        // Même mot de passe, réécrit seulement pour invalider les autres sessions : pas une modification du mot de passe.
        $user->passwordRehashOnly = true;
        Auth::logoutOtherDevices((string) $request->input('current_password'));
        $user->passwordRehashOnly = false;

        Audit::record('auth.sessions.closed_others', 'user', actorId: $user->id);

        return back()->with('status', __('ui.security.sessions_closed'));
    }

    public function requestEmailChange(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $this->reauthenticate($request, $user);

        $email = mb_strtolower(trim((string) $request->input('email')));
        $request->merge(['email' => $email]);
        $request->validate(['email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email'), Rule::notIn([mb_strtolower($user->email)])]], ['email.unique' => __('ui.errors.E1')]);

        $token = Str::random(64);
        DB::table('email_changes')->updateOrInsert(['user_id' => $user->id], ['new_email' => $email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHours((int) config('rendivoo.security.email_link_ttl_hours')), 'created_at' => now()]);

        // Lien vers la NOUVELLE adresse ; avis à l'ANCIENNE (flowchart 3C). L'adresse ne change qu'à la confirmation.
        Notification::route('mail', $email)->notify(new ConfirmNewEmailNotification($token, $user->locale));
        $user->notify(new EmailChangeNoticeNotification(EmailChangeNoticeNotification::REQUESTED, $email, $user->locale));
        Audit::record('auth.email_change.requested', 'user', actorId: $user->id);

        return back()->with('status', __('ui.security.email_link_sent', ['email' => $email]));
    }

    public function confirmEmailChange(Request $request, string $token): RedirectResponse
    {
        $user = $this->user($request);
        $change = DB::table('email_changes')->where('user_id', $user->id)->first();

        if ($change === null || now()->gt($change->expires_at) || ! hash_equals((string) $change->token_hash, hash('sha256', $token))) {
            return redirect()->route('settings.profile.show')->withErrors(['email' => __('ui.security.email_link_invalid')]);
        }

        $old = $user->email;

        if (User::query()->where('email', $change->new_email)->exists()) {
            DB::table('email_changes')->where('user_id', $user->id)->delete();

            return redirect()->route('settings.profile.show')->withErrors(['email' => __('ui.errors.E1')]);
        }

        // La nouvelle adresse vient d'être prouvée par ce lien : elle est vérifiée d'office.
        $user->forceFill(['email' => $change->new_email, 'email_verified_at' => now()])->save();
        DB::table('email_changes')->where('user_id', $user->id)->delete();
        Audit::record('auth.email_changed', 'user', actorId: $user->id);

        Notification::route('mail', $old)->notify(new EmailChangeNoticeNotification(EmailChangeNoticeNotification::DONE, $change->new_email, $user->locale));

        return redirect()->route('settings.profile.show')->with('status', __('ui.security.email_changed'));
    }

    public function requestPhoneChange(Request $request, PhoneChange $phoneChange): RedirectResponse
    {
        $user = $this->user($request);
        $this->reauthenticate($request, $user);
        $country = app(CurrentTenant::class)->organization()?->country;
        $request->validate(['phone' => ['required', 'string', new ValidPhone($country)]]);

        $phone = Phone::toE164($request->string('phone')->toString(), $country) ?? throw new UnexpectedValueException('Numéro non validé.');

        try {
            $phoneChange->request($user, $phone);
        } catch (TooManyAttempts $e) {
            return back()->withErrors(['phone' => __('ui.errors.E2_blocked', ['minutes' => $e->retryAfterMinutes()])]);
        }

        return back()->with('status', __('ui.security.phone_code_sent'));
    }

    public function confirmPhoneChange(Request $request, PhoneChange $phoneChange): RedirectResponse
    {
        $user = $this->user($request);
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        try {
            $done = $phoneChange->confirm($user, $request->string('code')->toString());
        } catch (TooManyAttempts $e) {
            return back()->withErrors(['code' => __('ui.errors.E2_blocked', ['minutes' => $e->retryAfterMinutes()])]);
        }

        return $done ? back()->with('status', __('ui.security.phone_changed')) : back()->withErrors(['code' => __('ui.security.phone_code_invalid')]);
    }

    public function beginTwoFactor(Request $request): RedirectResponse
    {
        $this->twoFactor->beginSetup($this->user($request));

        return redirect()->route('security.show');
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $request->validate(['code' => ['required', 'string', 'max:20']]);

        $codes = $this->twoFactor->confirm($user, $request->string('code')->toString());

        if ($codes === null) {
            return back()->withErrors(['code' => __('ui.security.two_factor_code_invalid')]);
        }

        // Les codes de secours ne s'affichent qu'une fois, sur la page suivante.
        return redirect()->route('security.show')->with('recovery_codes', $codes)->with('status', __('ui.security.two_factor_enabled'));
    }

    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $this->reauthenticate($request, $user);

        abort_unless($user->hasTwoFactor(), 404);

        return redirect()->route('security.show')->with('recovery_codes', $this->twoFactor->regenerateRecoveryCodes($user));
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        // Obligatoire pour les propriétaires et responsables : on ne peut pas la couper.
        if ($this->twoFactor->isRequiredFor($this->tenant->role())) {
            return back()->withErrors(['two_factor' => __('ui.security.two_factor_required_cannot_disable')]);
        }

        $this->reauthenticate($request, $user);

        if (! $this->twoFactor->verify($user, $request->string('code')->toString())) {
            return back()->withErrors(['code' => __('ui.security.two_factor_code_invalid')]);
        }

        $this->twoFactor->disable($user);

        return back()->with('status', __('ui.security.two_factor_disabled'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }

    private function reauthenticate(Request $request, User $user): void
    {
        $key = 'security-reauth:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['current_password' => __('ui.errors.E3', ['minutes' => (int) ceil(RateLimiter::availableIn($key) / 60)])]);
        }

        if (! Hash::check((string) $request->input('current_password'), $user->password)) {
            RateLimiter::hit($key, 900);

            throw ValidationException::withMessages(['current_password' => __('ui.security.current_password_wrong')]);
        }

        RateLimiter::clear($key);
    }
}
