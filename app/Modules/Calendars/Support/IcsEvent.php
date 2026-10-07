<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Support;

use DateTimeZone;

/**
 * Un VEVENT lu dans un fichier iCalendar. Le contenu (titre, participants, lieu) n'est JAMAIS conservé :
 * seuls l'identifiant et les horaires servent à bloquer le temps du praticien.
 */
final readonly class IcsEvent
{
    /**
     * @param  int  $start  secondes Unix
     * @param  int  $end  secondes Unix (jamais avant le début)
     * @param  array<string, string>|null  $rrule  règle de récurrence (clés en majuscules)
     * @param  list<int>  $exdates  débuts exclus (secondes Unix) ; une date seule vaut pour toute la journée : voir `$exdateDays`
     * @param  list<string>  $exdateDays  jours exclus (AAAA-MM-JJ) pour les EXDATE sans heure
     */
    public function __construct(
        public string $uid,
        public int $start,
        public int $end,
        public bool $allDay,
        public DateTimeZone $timezone,
        public ?array $rrule,
        public array $exdates,
        public array $exdateDays,
        public ?int $recurrenceId,
        public bool $cancelled,
        public bool $transparent,
    ) {}
}
