<?php

declare(strict_types=1);

namespace App\Support\Files;

/**
 * Contrôle minimal d'un PDF reçu : signature et contenu actif (RGPD art. 32).
 *
 * Un PDF peut lancer un programme (/Launch), exécuter du JavaScript (/JavaScript, /JS) ou embarquer un fichier ou un média
 * (/EmbeddedFile, /RichMedia). Aucun de ces éléments n'a sa place dans un document juridique ou une pièce jointe d'assistance : le fichier est refusé.
 * Limite connue : une recherche de marqueurs ne voit pas les objets compressés en flux ; elle ne remplace pas un antivirus.
 */
final class PdfInspector
{
    private const ACTIVE_CONTENT = '#/(Launch|JavaScript|JS|EmbeddedFile|RichMedia)(?![A-Za-z0-9])#';

    public static function hasSignature(string $contents): bool
    {
        return str_contains(substr($contents, 0, 1024), '%PDF-');
    }

    public static function hasActiveContent(string $contents): bool
    {
        return preg_match(self::ACTIVE_CONTENT, $contents) === 1;
    }
}
