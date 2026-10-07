<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Support;

use App\Modules\Conversation\Contracts\LanguageModel;

/** Aucun modèle de langage : l'assistant ne s'appuie que sur ses menus et ses règles. */
final class NullModel implements LanguageModel
{
    public function available(): bool
    {
        return false;
    }

    public function complete(string $system, string $user): ?string
    {
        return null;
    }
}
