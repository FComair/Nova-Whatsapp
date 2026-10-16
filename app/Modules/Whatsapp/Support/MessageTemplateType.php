<?php

declare(strict_types=1);

namespace App\Modules\Whatsapp\Support;

/**
 * Types de messages WhatsApp de la bibliothèque (docs/39). Les quatre premiers sont envoyés automatiquement par Nova
 * (réservation, rappel, déplacement, annulation) ; les autres sont prêts à l'emploi mais n'ont pas encore de déclencheur dans l'application.
 */
final class MessageTemplateType
{
    public const WELCOME = 'welcome';

    public const CONFIRMATION = 'confirmation';

    public const REMINDER = 'reminder';

    public const MODIFICATION = 'modification';

    public const CANCELLATION = 'cancellation';

    public const PAYMENT_REMINDER = 'payment_reminder';

    public const PAYMENT_CONFIRMATION = 'payment_confirmation';

    public const FOLLOW_UP = 'follow_up';

    public const REVIEW_REQUEST = 'review_request';

    public const RE_ENGAGEMENT = 're_engagement';

    public const PROMOTIONAL = 'promotional';

    public const INFORMATIONAL = 'informational';

    public const CUSTOM = 'custom';

    public const ALL = [
        self::WELCOME, self::CONFIRMATION, self::REMINDER, self::MODIFICATION, self::CANCELLATION, self::PAYMENT_REMINDER,
        self::PAYMENT_CONFIRMATION, self::FOLLOW_UP, self::REVIEW_REQUEST, self::RE_ENGAGEMENT, self::PROMOTIONAL, self::INFORMATIONAL, self::CUSTOM,
    ];

    /** Types que Nova envoie lui-même, avec la clé de l'ancien modèle global (config/whatsapp.php) qu'ils remplacent. */
    public const AUTOMATIC = [
        self::CONFIRMATION => 'confirmation',
        self::REMINDER => 'reminder',
        self::MODIFICATION => 'rescheduled',
        self::CANCELLATION => 'cancellation',
    ];

    /** Catégorie Meta : « marketing » pour ce qui cherche à vendre ou à relancer, « utility » pour le reste. */
    public static function category(string $type): string
    {
        return in_array($type, [self::REVIEW_REQUEST, self::RE_ENGAGEMENT, self::PROMOTIONAL], true) ? 'marketing' : 'utility';
    }

    public static function isAutomatic(string $type): bool
    {
        return array_key_exists($type, self::AUTOMATIC);
    }
}
