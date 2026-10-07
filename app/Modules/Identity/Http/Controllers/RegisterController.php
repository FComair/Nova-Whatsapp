<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Exceptions\TooManyAttempts;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Services\RegisterOrganization;
use App\Modules\Identity\Services\SendLimiter;
use App\Modules\Identity\Support\PendingVerification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Propaganistas\LaravelPhone\PhoneNumber;

final class RegisterController
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Register', [
            'sectors' => config('nova.sectors'),
            'plan' => in_array($request->query('plan'), config('nova.signup_plans'), true) ? $request->query('plan') : 'trial',
        ]);
    }

    public function store(RegisterRequest $request, RegisterOrganization $register, SendLimiter $limiter): RedirectResponse
    {
        $phone = (new PhoneNumber($request->string('phone')->toString()))->formatE164();

        try {
            $user = $register->handle([
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'phone_e164' => $phone,
                'sector' => $request->string('sector')->toString(),
                'plan' => $request->input('plan'),
            ], app()->getLocale(), $request->ip());
        } catch (UniqueConstraintViolationException) {
            // Course entre deux inscriptions avec le même e-mail : même message que la validation (E1).
            return back()->withInput($request->except('password'))->withErrors(['email' => __('ui.errors.E1')]);
        }

        PendingVerification::remember($request, $user);

        try {
            $limiter->hit('email', $user->id);
            $user->notify(new VerifyEmailNotification);
        } catch (TooManyAttempts) {
            // Le premier envoi ne peut pas être limité : ignoré, l'utilisateur pourra demander un renvoi.
        }

        return redirect()->route('verification.notice');
    }
}
