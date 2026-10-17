<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Audit\Audit;
use App\Modules\Identity\Contracts\PhoneCodeSender;
use App\Modules\Identity\Exceptions\TooManyAttempts;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Code à 6 chiffres envoyé au téléphone professionnel (flowchart 1A).
 *
 * Le code n'est jamais stocké en clair : seule une empreinte HMAC vit dans Redis, avec une durée de vie courte.
 */
final class PhoneVerification
{
    public const VERIFIED = 'verified';

    public const INVALID = 'invalid';

    public const EXPIRED = 'expired';

    public function __construct(private readonly PhoneCodeSender $sender, private readonly SendLimiter $limiter) {}

    /**
     * @throws TooManyAttempts
     */
    public function issue(User $user): void
    {
        $this->assertNotBlocked($user);
        $this->limiter->hit('phone', $user->id);

        $length = (int) config('rendivoo.security.phone_code_length');
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        Cache::put(
            $this->codeKey($user),
            $this->fingerprint($user, $code),
            now()->addMinutes((int) config('rendivoo.security.phone_code_ttl_minutes')),
        );

        $this->sender->send((string) $user->phone_e164, $code, $user->locale);
    }

    /**
     * @return self::VERIFIED|self::INVALID|self::EXPIRED
     *
     * @throws TooManyAttempts
     */
    public function verify(User $user, string $code): string
    {
        $this->assertNotBlocked($user);

        $stored = Cache::get($this->codeKey($user));

        if (! is_string($stored)) {
            return self::EXPIRED;
        }

        if (! hash_equals($stored, $this->fingerprint($user, trim($code)))) {
            $failKey = $this->failKey($user);
            RateLimiter::hit($failKey, $this->blockSeconds());

            if (RateLimiter::tooManyAttempts($failKey, (int) config('rendivoo.security.phone_code_max_attempts'))) {
                Cache::forget($this->codeKey($user));
                Audit::record('auth.phone_verification.blocked', 'user', actorId: $user->id);

                throw new TooManyAttempts('E2', RateLimiter::availableIn($failKey));
            }

            return self::INVALID;
        }

        Cache::forget($this->codeKey($user));
        RateLimiter::clear($this->failKey($user));
        $user->forceFill(['phone_verified_at' => now()])->save();

        return self::VERIFIED;
    }

    private function assertNotBlocked(User $user): void
    {
        $failKey = $this->failKey($user);

        if (RateLimiter::tooManyAttempts($failKey, (int) config('rendivoo.security.phone_code_max_attempts'))) {
            throw new TooManyAttempts('E2', RateLimiter::availableIn($failKey));
        }
    }

    private function fingerprint(User $user, string $code): string
    {
        // Lié à l'utilisateur ET au numéro : changer de numéro invalide tout code en cours.
        return hash_hmac('sha256', $user->id.'|'.$user->phone_e164.'|'.$code, (string) config('app.key'));
    }

    private function codeKey(User $user): string
    {
        return "phone-code:{$user->id}";
    }

    private function failKey(User $user): string
    {
        return "phone-fail:{$user->id}";
    }

    private function blockSeconds(): int
    {
        return (int) config('rendivoo.security.verification_block_minutes') * 60;
    }
}
