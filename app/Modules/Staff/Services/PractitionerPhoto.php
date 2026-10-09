<?php

declare(strict_types=1);

namespace App\Modules\Staff\Services;

use App\Modules\Audit\Audit;
use App\Modules\Staff\Models\Practitioner;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Photo de profil d'un praticien : relecture, recadrage carré, deux tailles, stockage privé.
 *
 * - L'image est relue puis réécrite en JPEG : métadonnées (position GPS, appareil) et contenu caché disparaissent ; la
 *   transparence est aplatie sur fond blanc. JPEG, PNG et WebP sont acceptés (WebP si la bibliothèque graphique du serveur le lit).
 * - Recadrage carré au centre, un peu remonté pour une image en portrait afin de garder le visage ; 64 px pour les listes, les
 *   sélecteurs et les cartes du planning, 256 px pour les profils (écrans à haute densité compris).
 * - Un remplacement qui échoue ne touche jamais la photo existante : tout est calculé en mémoire avant d'écrire un seul fichier.
 * - Fichiers sur le disque privé, un dossier par établissement et par praticien ; servis uniquement aux membres de l'établissement.
 */
final class PractitionerPhoto
{
    public const SIZES = [64, 256];

    public const MIN_SIDE = 200;

    public const MAX_SIDE = 6000;

    public const MAX_KILOBYTES = 5120;

    private const DISK = 'local';

    /**
     * Types lisibles par ce serveur (le WebP dépend de la bibliothèque graphique installée).
     *
     * @return list<int>
     */
    public static function acceptedTypes(): array
    {
        return [IMAGETYPE_JPEG, IMAGETYPE_PNG, ...(function_exists('imagecreatefromwebp') ? [IMAGETYPE_WEBP] : [])];
    }

    /**
     * Règles de validation d'un champ photo (formulaire du praticien et envoi direct).
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return [
            'file',
            'max:'.self::MAX_KILOBYTES,
            'mimes:jpg,jpeg,png'.(function_exists('imagecreatefromwebp') ? ',webp' : ''),
            'dimensions:min_width='.self::MIN_SIDE.',min_height='.self::MIN_SIDE.',max_width='.self::MAX_SIDE.',max_height='.self::MAX_SIDE,
        ];
    }

    /**
     * Messages clairs pour chaque refus, dans la langue de l'interface.
     *
     * @return array<string, string>
     */
    public static function messages(string $field = 'photo'): array
    {
        return [
            "{$field}.file" => __('ui.practitioner_photo.errors.invalid'),
            "{$field}.uploaded" => __('ui.practitioner_photo.errors.too_large'),
            "{$field}.max" => __('ui.practitioner_photo.errors.too_large'),
            "{$field}.mimes" => __('ui.practitioner_photo.errors.type'),
            "{$field}.dimensions" => __('ui.practitioner_photo.errors.dimensions'),
        ];
    }

    /**
     * @throws InvalidArgumentException image illisible, format ou dimensions non acceptés
     */
    public function store(Practitioner $practitioner, UploadedFile $file, ?string $actorId = null): void
    {
        $source = $this->read($file);
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $cropX = intdiv($width - $side, 2);
        $cropY = intdiv($height - $side, 4);
        $encoded = [];

        foreach (self::SIZES as $size) {
            $thumb = imagecreatetruecolor($size, $size);
            imagefill($thumb, 0, 0, (int) imagecolorallocate($thumb, 255, 255, 255));
            imagecopyresampled($thumb, $source, 0, 0, $cropX, $cropY, $size, $size, $side, $side);

            ob_start();
            imagejpeg($thumb, null, 85);
            $encoded[$size] = (string) ob_get_clean();
        }

        // Tout est prêt : seulement maintenant on remplace les fichiers.
        $disk = Storage::disk(self::DISK);

        foreach ($encoded as $size => $bytes) {
            $disk->put($this->path($practitioner, $size), $bytes);
        }

        $practitioner->forceFill(['photo_updated_at' => now()])->save();
        Audit::record('practitioner.photo.updated', 'user', actorId: $actorId, orgId: $practitioner->org_id, entityType: 'practitioner', entityId: $practitioner->id);
    }

    public function remove(Practitioner $practitioner, ?string $actorId = null): void
    {
        $this->purge($practitioner);

        $practitioner->forceFill(['photo_updated_at' => null])->save();
        Audit::record('practitioner.photo.removed', 'user', actorId: $actorId, orgId: $practitioner->org_id, entityType: 'practitioner', entityId: $practitioner->id);
    }

    /** Supprime les fichiers d'un praticien (retrait de la photo, suppression du praticien). */
    public function purge(Practitioner $practitioner): void
    {
        Storage::disk(self::DISK)->deleteDirectory($this->directory($practitioner->org_id, $practitioner->id));
    }

    /** Supprime les fichiers de tous les praticiens d'un établissement (effacement du compte). */
    public function purgeOrganization(string $organizationId): void
    {
        Storage::disk(self::DISK)->deleteDirectory("practitioners/{$organizationId}");
    }

    public function path(Practitioner $practitioner, int $size): string
    {
        return $this->directory($practitioner->org_id, $practitioner->id).'/'.$size.'.jpg';
    }

    private function directory(string $organizationId, string $practitionerId): string
    {
        return "practitioners/{$organizationId}/{$practitionerId}";
    }

    private function read(UploadedFile $file): GdImage
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! in_array($info[2], self::acceptedTypes(), true)) {
            throw new InvalidArgumentException('type');
        }

        if ($info[0] < self::MIN_SIDE || $info[1] < self::MIN_SIDE || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new InvalidArgumentException('dimensions');
        }

        $path = $file->getRealPath();
        $image = match ($info[2]) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => @imagecreatefromjpeg($path),
        };

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('unreadable');
        }

        return $image;
    }
}
