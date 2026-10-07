<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Contracts\PhoneCodeSender;

/**
 * Remplace l'envoi réel du code téléphonique et garde le dernier code envoyé pour les tests.
 */
final class FakePhoneCodeSender implements PhoneCodeSender
{
    /** @var list<array{phone: string, code: string, locale: string}> */
    public array $sent = [];

    public function send(string $phoneE164, string $code, string $locale): void
    {
        $this->sent[] = ['phone' => $phoneE164, 'code' => $code, 'locale' => $locale];
    }

    public function lastCode(): string
    {
        return $this->sent[array_key_last($this->sent)]['code'] ?? throw new \LogicException('Aucun code envoyé.');
    }
}
