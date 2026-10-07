<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\PhoneNumber;

/**
 * Nouveau rendez-vous saisi depuis le dashboard (flowchart 2B). L'heure est saisie à l'heure locale du site.
 */
final class StoreBookingRequest extends FormRequest
{
    /** Pays dont les numéros nationaux sont acceptés (comme à l'inscription). */
    private const COUNTRIES = 'BE,FR,NL,LU,DE,GB';

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
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            'service_id' => ['required', 'uuid', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'practitioner_id' => ['required', 'uuid', Rule::exists('practitioners', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'phone' => ['required', 'string', 'phone:'.self::COUNTRIES],
            'name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function phoneE164(): string
    {
        return (new PhoneNumber($this->string('phone')->toString(), explode(',', self::COUNTRIES)))->formatE164();
    }

    /** Début du rendez-vous en UTC, à partir de la date et de l'heure locales du site. */
    public function startsAt(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $this->string('date').' '.$this->string('time'), $timezone)->utc();
    }
}
