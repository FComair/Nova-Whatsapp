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
 * Changement du téléphone professionnel (flowchart 3C) : un code est envoyé au NOUVEAU numéro ; l'ancien reste actif tant
 * que le nouveau n'est pas confirmé. Le code n'est jamais stocké en clair (empreinte HMAC, durée de vie courte).
 */
final class PhoneChange
{
    public function __construct(private readonly PhoneCodeSender $sender, private readonly SendLimiter $limiter) {}

    /**
     * @throws TooManyAttempts
     */
    public function request(User $user, string $newPhoneE164): void
    {
        $this->assertNotBlocked($user);
        $this->limiter->hit('phone-change', $user->id);

        $length = (int) config('nova.security.phone_code_length');
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        Cache::put($this->key($user), ['phone' => $newPhoneE164, 'fingerprint' => $this->fingerprint($user, $newPhoneE164, $code)], now()->addMinutes((int) config('nova.security.phone_code_ttl_minutes')));

        $this->sender->send($newPhoneE164, $code, $user->locale);
    }

    /** Numéro en attente de confirmation, s'il y en a un. */
    public function pending(User $user): ?string
    {
        $stored = Cache::get($this->key($user));

        return is_array($stored) ? (string) $stored['phone'] : null;
    }

    /**
     * @return bool vrai si le numéro est changé ; faux si le code est faux ou expiré
     *
     * @throws TooManyAttempts
     */
    public function confirm(User $user, string $code): bool
    {
        $this->assertNotBlocked($user);
        $stored = Cache::get($this->key($user));

        if (! is_array($stored)) {
            return false;
        }

        if (! hash_equals((string) $stored['fingerprint'], $this->fingerprint($user, (string) $stored['phone'], trim($code)))) {
            RateLimiter::hit($this->failKey($user), (int) config('nova.security.verification_block_minutes') * 60);

            if (RateLimiter::tooManyAttempts($this->failKey($user), (int) config('nova.security.phone_code_max_attempts'))) {
                Cache::forget($this->key($user));
                Audit::record('auth.phone_change.blocked', 'user', actorId: $user->id);
            }

            return false;
        }

        Cache::forget($this->key($user));
        RateLimiter::clear($this->failKey($user));
        $user->forceFill(['phone_e164' => $stored['phone'], 'phone_verified_at' => now()])->save();
        Audit::record('auth.phone_changed', 'user', actorId: $user->id);

        return true;
    }

    private function assertNotBlocked(User $user): void
    {
        if (RateLimiter::tooManyAttempts($this->failKey($user), (int) config('nova.security.phone_code_max_attempts'))) {
            throw new TooManyAttempts('E2', RateLimiter::availableIn($this->failKey($user)));
        }
    }

    private function fingerprint(User $user, string $phone, string $code): string
    {
        return hash_hmac('sha256', $user->id.'|'.$phone.'|'.$code, (string) config('app.key'));
    }

    private function key(User $user): string
    {
        return "phone-change:{$user->id}";
    }

    private function failKey(User $user): string
    {
        return "phone-change-fail:{$user->id}";
    }
}
