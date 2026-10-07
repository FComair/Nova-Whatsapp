<?php

declare(strict_types=1);

namespace App\Modules\Conversation\Contracts;

/**
 * Modèle de langage utilisé en repli (compréhension d'un message libre, réponses de la FAQ).
 */
interface LanguageModel
{
    /** Faux quand aucune clé n'est configurée : l'assistant reste alors sur ses menus. */
    public function available(): bool;

    /**
     * @return string|null la réponse, ou null en cas d'indisponibilité ou d'échec (l'appelant a toujours un repli)
     */
    public function complete(string $system, string $user): ?string;
}
