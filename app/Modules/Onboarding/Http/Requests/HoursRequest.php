<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Étape 3 : horaires d'ouverture d'un site. `hours` contient les 7 jours ; un jour sans plage est fermé.
 */
class HoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Les horaires d'ouverture d'un site doivent contenir au moins une plage ; un planning de praticien peut être vide. */
    protected function requiresOpenDay(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'hours' => ['required', 'array', 'size:7'],
            'hours.*.weekday' => ['required', 'integer', 'between:1,7', 'distinct'],
            'hours.*.ranges' => ['present', 'array', 'max:3'],
            'hours.*.ranges.*.start' => ['required', 'date_format:H:i'],
            'hours.*.ranges.*.end' => ['required', 'date_format:H:i'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $open = 0;

            foreach ((array) $this->input('hours') as $index => $day) {
                /** @var list<array{start: string, end: string}> $rawRanges */
                $rawRanges = $day['ranges'];
                $ranges = collect($rawRanges)->sortBy('start')->values();
                $open += $ranges->count();

                foreach ($ranges as $i => $range) {
                    if ($range['end'] <= $range['start']) {
                        $validator->errors()->add("hours.{$index}.ranges.{$i}.end", __('ui.onboarding.errors.hours_end_after_start'));
                    }

                    if ($i > 0 && $range['start'] < $ranges[$i - 1]['end']) {
                        $validator->errors()->add("hours.{$index}.ranges.{$i}.start", __('ui.onboarding.errors.hours_overlap'));
                    }
                }
            }

            if ($open === 0 && $this->requiresOpenDay()) {
                $validator->errors()->add('hours', __('ui.onboarding.errors.hours_none_open'));
            }
        });
    }

    /**
     * Horaires normalisés : jours 1 à 7, plages triées.
     *
     * @return array<int, list<array{start: string, end: string}>>
     */
    public function week(): array
    {
        $week = array_fill_keys(range(1, 7), []);

        foreach ((array) $this->input('hours') as $day) {
            /** @var list<array{start: string, end: string}> $rawRanges */
            $rawRanges = $day['ranges'];
            $week[(int) $day['weekday']] = collect($rawRanges)
                ->map(fn (array $range): array => ['start' => $range['start'], 'end' => $range['end']])
                ->sortBy('start')->values()->all();
        }

        return $week;
    }
}
