<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Exceptions\InvalidCredentials;
use App\Modules\Identity\Exceptions\TooManyAttempts;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\SuspiciousLoginNotification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Contrôle des identifiants avec verrouillage (flowcharts 2A et 5A/E3).
 *
 * - Message unique « identifiants incorrects » : jamais de précision sur l'e-mail ou le mot de passe.
 * - Après 5 échecs : verrouillage de 15 minutes et alerte par e-mail au titulaire du compte.
 * - Les e-mails inconnus sont limités de la même façon, pour ne pas révéler si un compte existe.
 */
final class Authenticator
{
    /** Hachage factice pour égaliser le temps de réponse quand l'e-mail est inconnu. */
    private static ?string $dummyHash = null;

    /**
     * @throws InvalidCredentials
     * @throws TooManyAttempts
     */
    public function attempt(string $email, string $password): User
    {
        $email = mb_strtolower(trim($email));
        $unknownKey = 'login-unknown:'.hash('sha256', $email.'|'.request()->ip());
        $max = (int) config('rendivoo.security.login_max_attempts');
        $lockSeconds = (int) config('rendivoo.security.login_lock_minutes') * 60;

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            Hash::check($password, self::$dummyHash ??= Hash::make(Str::random(40)));

            if (RateLimiter::tooManyAttempts($unknownKey, $max)) {
                throw new TooManyAttempts('E3', RateLimiter::availableIn($unknownKey));
            }

            // Même comportement qu'un vrai compte : au 5e échec, verrouillage et message E3.
            if (RateLimiter::hit($unknownKey, $lockSeconds) >= $max) {
                throw new TooManyAttempts('E3', RateLimiter::availableIn($unknownKey));
            }

            throw new InvalidCredentials;
        }

        if ($user->locked_until !== null && $user->locked_until->isFuture()) {
            throw new TooManyAttempts('E3', (int) now()->diffInSeconds($user->locked_until, true));
        }

        if ($user->status !== 'active' || ! Hash::check($password, $user->password)) {
            $this->registerFailure($user, $max, $lockSeconds);

            throw new InvalidCredentials;
        }

        $user->forceFill(['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();

        if (Hash::needsRehash($user->password)) {
            $user->passwordRehashOnly = true;
            $user->forceFill(['password' => $password])->save();
            $user->passwordRehashOnly = false;
        }

        return $user;
    }

    private function registerFailure(User $user, int $max, int $lockSeconds): void
    {
        $count = $user->failed_login_count + 1;

        if ($count < $max) {
            $user->forceFill(['failed_login_count' => $count])->save();

            return;
        }

        $user->forceFill(['failed_login_count' => 0, 'locked_until' => now()->addSeconds($lockSeconds)])->save();

        Audit::record('auth.login.locked', 'user', actorId: $user->id, reason: "{$max} échecs consécutifs");
        $user->notify(new SuspiciousLoginNotification((int) config('rendivoo.security.login_lock_minutes')));

        throw new TooManyAttempts('E3', $lockSeconds);
    }
}
