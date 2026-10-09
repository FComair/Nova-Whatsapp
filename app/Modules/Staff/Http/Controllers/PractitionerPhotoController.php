<?php

declare(strict_types=1);

namespace App\Modules\Staff\Http\Controllers;

use App\Modules\Staff\Models\Practitioner;
use App\Modules\Staff\Services\PractitionerPhoto;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert la photo d'un praticien aux membres de son établissement (la RLS rend le praticien d'un autre introuvable).
 * L'adresse porte la version de la photo : le navigateur la garde longtemps et la recharge dès qu'elle change.
 */
final class PractitionerPhotoController
{
    public function __construct(private readonly PractitionerPhoto $photos) {}

    public function show(Practitioner $practitioner, int $size): StreamedResponse
    {
        abort_unless($practitioner->photo_updated_at !== null && in_array($size, PractitionerPhoto::SIZES, true), 404);

        $path = $this->photos->path($practitioner, $size);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
