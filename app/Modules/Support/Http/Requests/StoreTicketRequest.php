<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Services\AttachmentStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Formulaire « Contacter le support » (docs/22 §4).
 */
final class StoreTicketRequest extends FormRequest
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
            'subject' => ['required', 'string', 'min:5', 'max:120'],
            'category' => ['required', Rule::in(SupportTicket::CATEGORIES)],
            'related_to' => ['nullable', Rule::in(SupportTicket::RELATED)],
            'urgency' => ['required', Rule::in(SupportTicket::URGENCIES)],
            'description' => ['required', 'string', 'min:20', 'max:5000'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'uuid'],
            'attachments' => ['nullable', 'array', 'max:'.AttachmentStore::MAX_FILES],
            'attachments.*' => ['file', 'max:'.AttachmentStore::MAX_KILOBYTES, 'mimes:png,jpg,jpeg,pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'subject' => __('ui.support.fields.subject'),
            'category' => __('ui.support.fields.category'),
            'related_to' => __('ui.support.fields.related_to'),
            'urgency' => __('ui.support.fields.urgency'),
            'description' => __('ui.support.fields.description'),
            'email' => __('ui.support.fields.email'),
            'attachments' => __('ui.support.fields.attachments'),
        ];
    }
}
