<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Billing\Services\SignupPlans;
use App\Modules\Identity\Exceptions\TooManyAttempts;
use App\Modules\Identity\Http\Requests\RegisterRequest;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use App\Modules\Identity\Services\RegisterOrganization;
use App\Modules\Identity\Services\SendLimiter;
use App\Modules\Identity\Support\PendingVerification;
use App\Modules\Tenancy\Services\SectorCatalog;
use App\Support\Phone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

final class RegisterController
{
    public function create(Request $request, SignupPlans $plans): Response
    {
        return Inertia::render('Auth/Register', [
            'sectors' => app(SectorCatalog::class)->options(app()->getLocale()),
            // Formule préchoisie par un lien (?plan=…) seulement si elle existe encore ; sinon l'essai.
            'plan' => in_array($request->query('plan'), $plans->codes(), true) ? $request->query('plan') : SignupPlans::TRIAL,
            'offers' => $plans->options(),
        ]);
    }

    public function store(RegisterRequest $request, RegisterOrganization $register, SendLimiter $limiter): RedirectResponse
    {
        $phone = Phone::toE164($request->string('phone')->toString()) ?? throw new UnexpectedValueException('Numéro non validé.');

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
