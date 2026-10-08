<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use App\Modules\Tenancy\Support\CurrentTenant;
use App\Support\Phone;
use App\Support\ValidPhone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

/**
 * Nouveau rendez-vous saisi depuis le dashboard (flowchart 2B). L'heure est saisie à l'heure locale du site.
 */
final class StoreBookingRequest extends FormRequest
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
            'location_id' => ['required', 'uuid', Rule::exists('locations', 'id')],
            'service_id' => ['required', 'uuid', Rule::exists('services', 'id')->whereNull('deleted_at')],
            'practitioner_id' => ['required', 'uuid', Rule::exists('practitioners', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'phone' => ['required', 'string', new ValidPhone($this->defaultCountry())],
            'name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function phoneE164(): string
    {
        return Phone::toE164($this->string('phone')->toString(), $this->defaultCountry()) ?? throw new UnexpectedValueException('Numéro non validé.');
    }

    /** Pays de l'établissement : il lit les numéros nationaux (06 12 34 56 78) sans deviner un autre pays. */
    private function defaultCountry(): ?string
    {
        return app(CurrentTenant::class)->organization()?->country;
    }

    /** Début du rendez-vous en UTC, à partir de la date et de l'heure locales du site. */
    public function startsAt(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $this->string('date').' '.$this->string('time'), $timezone)->utc();
    }
}
