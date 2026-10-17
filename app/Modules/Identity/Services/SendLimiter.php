<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Exceptions\TooManyAttempts;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limite les envois de liens et de codes : renvoi possible après un délai, et nombre d'envois par heure plafonné
 * (flowcharts 1A et 5A/E2 : 60 s de délai, 3 envois par heure).
 */
final class SendLimiter
{
    /**
     * @throws TooManyAttempts
     */
    public function hit(string $scope, string $subjectId): void
    {
        $cooldownKey = "send-cooldown:{$scope}:{$subjectId}";
        $hourKey = "send-hour:{$scope}:{$subjectId}";

        if (RateLimiter::tooManyAttempts($cooldownKey, 1)) {
            throw new TooManyAttempts('E2', RateLimiter::availableIn($cooldownKey));
        }

        if (RateLimiter::tooManyAttempts($hourKey, (int) config('rendivoo.security.resend_per_hour'))) {
            throw new TooManyAttempts('E2', RateLimiter::availableIn($hourKey));
        }

        RateLimiter::hit($cooldownKey, (int) config('rendivoo.security.resend_cooldown_seconds'));
        RateLimiter::hit($hourKey, 3600);
    }
}
