<?php

declare(strict_types=1);

namespace App\Modules\Booking\Support;

use Carbon\CarbonImmutable;

/**
 * Jours chargés et affichés par le planning selon la vue choisie :
 * - day : le jour choisi ;
 * - 3days : le jour choisi et les deux suivants (fenêtre glissante, même à cheval sur deux semaines) ;
 * - week : du lundi au dimanche de la semaine du jour choisi (l'écran masque les jours fermés : semaine de travail) ;
 * - practitioners : la même semaine (colonnes par praticien pour le jour choisi, ou semaine d'un seul praticien).
 */
final class PlanningWindow
{
    public const DAY = 'day';

    public const THREE_DAYS = '3days';

    public const WEEK = 'week';

    public const PRACTITIONERS = 'practitioners';

    public const VIEWS = [self::DAY, self::THREE_DAYS, self::WEEK, self::PRACTITIONERS];

    public static function view(mixed $value): string
    {
        return is_string($value) && in_array($value, self::VIEWS, true) ? $value : self::WEEK;
    }

    /**
     * @return list<string> jours « AAAA-MM-JJ » dans l'ordre
     */
    public static function days(string $view, string $date): array
    {
        $anchor = CarbonImmutable::parse($date)->startOfDay();

        [$first, $count] = match ($view) {
            self::DAY => [$anchor, 1],
            self::THREE_DAYS => [$anchor, 3],
            default => [$anchor->startOfWeek(CarbonImmutable::MONDAY), 7],
        };

        return array_map(fn (int $offset): string => $first->addDays($offset)->toDateString(), range(0, $count - 1));
    }
}
