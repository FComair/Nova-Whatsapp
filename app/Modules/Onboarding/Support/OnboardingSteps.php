<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Support;

/**
 * Les 9 étapes de l'assistant d'onboarding (spec §5, flowcharts 1B à 1D).
 *
 * Seules les étapes listées dans IMPLEMENTED ont un écran ; les autres apparaissent dans la progression
 * comme « bientôt disponibles » tant que leur module (services, praticiens, politiques, WhatsApp) n'est pas construit.
 */
final class OnboardingSteps
{
    public const COMPANY = 'company';

    public const LOCATIONS = 'locations';

    public const HOURS = 'hours';

    public const SERVICES = 'services';

    public const PRACTITIONERS = 'practitioners';

    public const POLICIES = 'policies';

    public const WHATSAPP = 'whatsapp';

    public const TEMPLATES = 'templates';

    public const TEST_BOOKING = 'test';

    /** Ordre des étapes. */
    public const ALL = [
        self::COMPANY, self::LOCATIONS, self::HOURS, self::SERVICES, self::PRACTITIONERS,
        self::POLICIES, self::WHATSAPP, self::TEMPLATES, self::TEST_BOOKING,
    ];

    /** Étapes disposant d'un écran à ce jour. */
    public const IMPLEMENTED = [self::COMPANY, self::LOCATIONS, self::HOURS, self::SERVICES, self::PRACTITIONERS, self::POLICIES, self::WHATSAPP, self::TEMPLATES];

    /** Segment d'URL (en français) de chaque étape. */
    public const SLUGS = [
        self::COMPANY => 'entreprise',
        self::LOCATIONS => 'sites',
        self::HOURS => 'horaires',
        self::SERVICES => 'services',
        self::PRACTITIONERS => 'praticiens',
        self::POLICIES => 'politiques',
        self::WHATSAPP => 'whatsapp',
        self::TEMPLATES => 'messages-types',
        self::TEST_BOOKING => 'reservation-test',
    ];

    public static function fromSlug(string $slug): ?string
    {
        $step = array_search($slug, self::SLUGS, true);

        return $step === false ? null : (string) $step;
    }

    public static function isImplemented(string $step): bool
    {
        return in_array($step, self::IMPLEMENTED, true);
    }

    public static function number(string $step): int
    {
        return (int) array_search($step, self::ALL, true) + 1;
    }
}
