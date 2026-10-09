<?php

declare(strict_types=1);

namespace App\Modules\Support\Http\Requests;

use App\Modules\Support\Services\AttachmentStore;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Réponse du client dans la conversation d'un ticket (docs/22 §6).
 */
final class ReplyRequest extends FormRequest
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
            'body' => ['required', 'string', 'min:2', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:'.AttachmentStore::MAX_FILES],
            'attachments.*' => ['file', 'max:'.AttachmentStore::MAX_KILOBYTES, 'mimes:png,jpg,jpeg,pdf'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['body' => __('ui.support.reply.label'), 'attachments' => __('ui.support.fields.attachments')];
    }
}
