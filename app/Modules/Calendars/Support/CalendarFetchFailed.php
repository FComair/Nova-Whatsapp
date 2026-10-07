<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Support;

use RuntimeException;

/**
 * Le calendrier n'a pas pu être lu. Le code (jamais l'adresse ni le contenu) est conservé pour l'affichage :
 * `invalid_url`, `blocked_host`, `redirect`, `timeout`, `too_large`, `invalid_ics`, `http_401`, `http_404`, `http_5xx`…
 */
final class CalendarFetchFailed extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct("Lecture du calendrier échouée ({$errorCode}).");
    }
}
