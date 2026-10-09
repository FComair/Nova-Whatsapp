<?php

declare(strict_types=1);

namespace App\Modules\Feedback\Http\Requests;

use App\Modules\Feedback\Services\FeedbackService;
use Illuminate\Foundation\Http\FormRequest;

/** Avis privé : une note de 1 à 5 (toutes acceptées), un commentaire facultatif, un ticket facultatif. */
final class FeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:'.FeedbackService::COMMENT_MAX],
            'ticket' => ['nullable', 'string', 'regex:/^NWS-\d{1,12}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'rating.required' => __('ui.feedback.errors.rating'),
            'rating.integer' => __('ui.feedback.errors.rating'),
            'rating.between' => __('ui.feedback.errors.rating'),
            'comment.max' => __('ui.feedback.errors.comment_max', ['max' => FeedbackService::COMMENT_MAX]),
            'ticket.regex' => __('ui.feedback.errors.ticket'),
        ];
    }
}
