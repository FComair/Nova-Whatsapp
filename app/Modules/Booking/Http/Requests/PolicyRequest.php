<?php

declare(strict_types=1);

namespace App\Modules\Booking\Http\Requests;

use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Politiques de réservation : délais, annulation, no-show, surbooking, rappel. Les durées sont saisies en heures ou en jours ;
 * les frais de no-show en euros (« 15 » ou « 15,50 »).
 */
final class PolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['no_show_fee' => is_scalar($this->input('no_show_fee')) ? trim((string) $this->input('no_show_fee')) : '']);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'min_notice_hours' => ['required', 'integer', 'between:0,168'],
            'max_horizon_days' => ['required', 'integer', 'between:1,365'],
            'cancellation_window_hours' => ['required', 'integer', 'between:0,168'],
            'no_show_fee' => ['required', 'string'],
            'allow_overbooking' => ['required', 'boolean'],
            'hold_minutes' => ['required', 'integer', 'between:5,10'],
            'reminder_hours_before' => ['required', 'integer', 'between:0,72'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $errors = $validator->errors();

            if (! $errors->has('no_show_fee')) {
                $cents = Money::parseToCents($this->string('no_show_fee')->toString());

                if ($cents === null) {
                    $errors->add('no_show_fee', __('ui.services.errors.price_invalid'));
                } elseif ($cents > 50000) {
                    $errors->add('no_show_fee', __('ui.policies.errors.fee_too_high'));
                }
            }

            // Le délai minimum doit rester inférieur à l'horizon, sinon aucune date ne serait réservable.
            if (! $errors->has('min_notice_hours') && ! $errors->has('max_horizon_days') && $this->integer('min_notice_hours') >= $this->integer('max_horizon_days') * 24) {
                $errors->add('min_notice_hours', __('ui.policies.errors.notice_beyond_horizon'));
            }
        });
    }

    /**
     * @return array{min_notice_hours: int, max_horizon_days: int, cancellation_window_hours: int, no_show_fee_cents: int, allow_overbooking: bool, hold_minutes: int, reminder_hours_before: int}
     */
    public function policy(): array
    {
        return [
            'min_notice_hours' => $this->integer('min_notice_hours'),
            'max_horizon_days' => $this->integer('max_horizon_days'),
            'cancellation_window_hours' => $this->integer('cancellation_window_hours'),
            'no_show_fee_cents' => Money::parseToCents($this->string('no_show_fee')->toString()) ?? 0,
            'allow_overbooking' => $this->boolean('allow_overbooking'),
            'hold_minutes' => $this->integer('hold_minutes'),
            'reminder_hours_before' => $this->integer('reminder_hours_before'),
        ];
    }
}
