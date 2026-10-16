<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

/**
 * États d'un modèle de la bibliothèque. Seul « approuvé » (et activé) permet un envoi réel.
 *
 * brouillon → en attente d'approbation → approuvé | refusé ; un modèle refusé se corrige (retour en brouillon) ; l'archivage retire
 * le modèle de l'usage sans le supprimer et se défait par « restaurer » (retour en brouillon).
 */
final class MessageTemplateStatus
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ARCHIVED = 'archived';

    public const ALL = [self::DRAFT, self::PENDING, self::APPROVED, self::REJECTED, self::ARCHIVED];

    /** Un modèle ne se supprime que s'il n'a jamais servi ou n'est plus en usage. */
    public const DELETABLE = [self::DRAFT, self::REJECTED, self::ARCHIVED];
}
