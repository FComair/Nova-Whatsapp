<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Déplacement d'un rendez-vous : nouvelle date et heure locales du site, et éventuellement un autre praticien.
 */
final class MoveBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'practitioner_id' => ['nullable', 'uuid', Rule::exists('practitioners', 'id')->whereNull('deleted_at')],
        ];
    }

    public function startsAt(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $this->string('date').' '.$this->string('time'), $timezone)->utc();
    }
}
