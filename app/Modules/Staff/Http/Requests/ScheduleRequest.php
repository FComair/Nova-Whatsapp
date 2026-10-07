<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Onboarding\Http\Requests\HoursRequest;

/**
 * Planning hebdomadaire d'un praticien sur un site : mêmes règles que les horaires d'ouverture
 * (plages valides, sans chevauchement, 3 au maximum par jour), mais un planning vide est autorisé.
 */
final class ScheduleRequest extends HoursRequest
{
    protected function requiresOpenDay(): bool
    {
        return false;
    }
}
