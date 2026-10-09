<?php

declare(strict_types=1);

namespace App\Modules\Support\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Enregistre les pièces jointes d'un message sur le disque privé.
 *
 * Le type est lu dans le contenu du fichier (jamais d'après son nom) : PNG, JPEG ou PDF seulement. Les images sont ré-encodées,
 * ce qui retire leurs métadonnées (position GPS, appareil) et tout contenu caché. Le nom d'origine est nettoyé pour l'affichage.
 */
final class AttachmentStore
{
    public const MAX_FILES = 3;

    public const MAX_KILOBYTES = 5120;

    /** Types acceptés : type MIME => extension. */
    public const TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'application/pdf' => 'pdf'];

    public const DISK = 'local';

    /**
     * @return array{original_name: string, mime: string, size: int, path: string}
     *
     * @throws InvalidArgumentException fichier illisible ou d'un type non accepté
     */
    public function store(UploadedFile $file, string $orgId, string $ticketId): array
    {
        $mime = (string) $file->getMimeType();
        $extension = self::TYPES[$mime] ?? throw new InvalidArgumentException('type');
        $contents = (string) file_get_contents($file->getRealPath());

        if ($mime !== 'application/pdf') {
            $contents = $this->reencode($contents, $mime);
        }

        $path = "support/{$orgId}/{$ticketId}/".Str::uuid7().'.'.$extension;
        Storage::disk(self::DISK)->put($path, $contents);

        return ['original_name' => $this->cleanName($file->getClientOriginalName(), $extension), 'mime' => $mime, 'size' => strlen($contents), 'path' => $path];
    }

    /** Ré-encode une image avec GD ; sans GD, le fichier est refusé plutôt que gardé tel quel avec ses métadonnées. */
    private function reencode(string $contents, string $mime): string
    {
        if (! function_exists('imagecreatefromstring')) {
            throw new InvalidArgumentException('image');
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw new InvalidArgumentException('image');
        }

        ob_start();

        if ($mime === 'image/png') {
            imagesavealpha($image, true);
            imagepng($image);
        } else {
            imagejpeg($image, null, 88);
        }

        $encoded = (string) ob_get_clean();
        imagedestroy($image);

        return $encoded;
    }

    private function cleanName(string $name, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $name)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $base), ' ._-');

        return Str::limit($base === '' ? 'fichier' : $base, 100, '').'.'.$extension;
    }
}
