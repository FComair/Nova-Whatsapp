<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Pages de la liste de prix : 1 à 10 images PNG ou JPEG, 10 Mo au plus chacune. */
final class PriceListFilesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:'.(int) config('pricelist.max_files')],
            'files.*' => ['required', 'file', 'mimetypes:image/png,image/jpeg', 'max:'.(int) config('pricelist.max_file_kb')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'files.required' => __('ui.price_import.errors.required'),
            'files.array' => __('ui.price_import.errors.required'),
            'files.min' => __('ui.price_import.errors.required'),
            'files.max' => __('ui.price_import.errors.too_many', ['max' => (int) config('pricelist.max_files')]),
            'files.*.required' => __('ui.price_import.errors.type'),
            'files.*.file' => __('ui.price_import.errors.type'),
            'files.*.mimetypes' => __('ui.price_import.errors.type'),
            'files.*.max' => __('ui.price_import.errors.too_large', ['mb' => intdiv((int) config('pricelist.max_file_kb'), 1024)]),
            'files.*.uploaded' => __('ui.price_import.errors.too_large', ['mb' => intdiv((int) config('pricelist.max_file_kb'), 1024)]),
        ];
    }
}
