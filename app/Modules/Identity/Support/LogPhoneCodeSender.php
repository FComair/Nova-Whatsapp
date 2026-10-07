<?php

declare(strict_types=1);

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Contracts\PhoneCodeSender;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pilote de développement : écrit le code dans les logs. Interdit en production (le code serait exposé).
 */
final class LogPhoneCodeSender implements PhoneCodeSender
{
    public function send(string $phoneE164, string $code, string $locale): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Aucun canal d\'envoi de code téléphonique n\'est configuré pour la production.');
        }

        Log::info('[dev] Code de vérification téléphone', ['phone' => $phoneE164, 'code' => $code, 'locale' => $locale]);
    }
}
