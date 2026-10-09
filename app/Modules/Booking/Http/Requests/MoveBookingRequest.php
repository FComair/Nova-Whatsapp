<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Support\PastStart;
use App\Modules\Tenancy\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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

    /**
     * Pas de nouveau début dans le passé (heure du site). Un rendez-vous dont on ne change ni la date ni l'heure garde son
     * horaire, même passé : seul un changement de date ou d'heure est contrôlé.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $booking = $this->route('booking');

            if (! $booking instanceof Booking || $validator->errors()->hasAny(['date', 'time'])) {
                return;
            }

            $location = Location::query()->find($booking->location_id);

            if ($location === null) {
                return;
            }

            $start = $this->startsAt($location->timezone);

            if ($start->getTimestamp() !== $booking->starts_at->getTimestamp() && PastStart::isPast($start)) {
                $validator->errors()->add('time', __('ui.planning.errors.past_time'));
            }
        }];
    }

    public function startsAt(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $this->string('date').' '.$this->string('time'), $timezone)->utc();
    }
}
