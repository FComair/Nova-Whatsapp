<?php

declare(strict_types=1);

namespace App\Modules\Identity\Contracts;

/**
 * Envoi du code de vérification du téléphone professionnel (flowchart 1A).
 *
 * Le canal réel (SMS ou modèle WhatsApp d'authentification) n'est pas encore décidé : le code métier ne dépend que
 * de cette interface.
 */
interface PhoneCodeSender
{
    public function send(string $phoneE164, string $code, string $locale): void;
}
