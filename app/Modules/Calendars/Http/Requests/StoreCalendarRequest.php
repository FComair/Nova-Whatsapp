<?php

declare(strict_types=1);

namespace App\Modules\Calendars\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Ajout d'un calendrier externe. L'adresse est contrôlée plus finement (réseau public seulement) avant tout enregistrement.
 */
final class StoreCalendarRequest extends FormRequest
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
            'practitioner_id' => ['required', 'uuid', Rule::exists('practitioners', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'in:ics,caldav'],
            'url' => ['required', 'string', 'max:2000', 'regex:#^(https|webcals?)://#i'],
            'username' => ['nullable', 'required_if:kind,caldav', 'string', 'max:255'],
            'password' => ['nullable', 'required_if:kind,caldav', 'string', 'max:255'],
        ];
    }
}
