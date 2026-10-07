<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Audit\Audit;
use App\Modules\Tenancy\Models\Organization;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Logo de l'établissement : réencodage, deux tailles, stockage privé.
 *
 * - L'image est relue puis réécrite en PNG : tout contenu caché (métadonnées de photo, données ajoutées au fichier) disparaît.
 * - Une image non carrée est recadrée au centre ; l'aperçu du formulaire montre exactement ce recadrage.
 * - Deux tailles : 64 px pour le menu, 256 px pour le profil. Le SVG n'est pas accepté (il peut contenir des scripts).
 * - Les fichiers vivent dans le stockage privé, un dossier par établissement ; ils ne sont servis qu'aux membres de cet établissement.
 */
final class OrganizationLogo
{
    public const SIZES = [64, 256];

    public const MIN_SIDE = 200;

    public const MAX_SIDE = 6000;

    private const DISK = 'local';

    /**
     * @throws InvalidArgumentException si le fichier n'est pas une image PNG ou JPEG lisible de taille acceptable
     */
    public function store(Organization $organization, UploadedFile $file, ?string $actorId = null): void
    {
        $source = $this->read($file);

        try {
            $side = min(imagesx($source), imagesy($source));
            $cropX = intdiv(imagesx($source) - $side, 2);
            $cropY = intdiv(imagesy($source) - $side, 2);
            $disk = Storage::disk(self::DISK);

            foreach (self::SIZES as $size) {
                $thumb = imagecreatetruecolor($size, $size);
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                imagefill($thumb, 0, 0, (int) imagecolorallocatealpha($thumb, 255, 255, 255, 127));
                imagecopyresampled($thumb, $source, 0, 0, $cropX, $cropY, $size, $size, $side, $side);

                ob_start();
                imagepng($thumb, null, 9);
                $disk->put($this->path($organization->id, $size), (string) ob_get_clean());
            }
        } finally {
            // GdImage est libéré à la destruction ; la variable est lâchée explicitement pour les gros fichiers.
            unset($source);
        }

        $organization->forceFill(['logo_updated_at' => now()])->save();
        Audit::record('org.logo.updated', 'user', actorId: $actorId, orgId: $organization->id, entityType: 'organization', entityId: $organization->id);
    }

    public function remove(Organization $organization, ?string $actorId = null): void
    {
        $this->purge($organization->id);

        $organization->forceFill(['logo_updated_at' => null])->save();
        Audit::record('org.logo.removed', 'user', actorId: $actorId, orgId: $organization->id, entityType: 'organization', entityId: $organization->id);
    }

    /** Supprime les fichiers (aussi utilisé à l'effacement du compte). */
    public function purge(string $organizationId): void
    {
        Storage::disk(self::DISK)->deleteDirectory("logos/{$organizationId}");
    }

    /** Chemin d'une taille du logo dans le stockage privé. */
    public function path(string $organizationId, int $size): string
    {
        return "logos/{$organizationId}/{$size}.png";
    }

    private function read(UploadedFile $file): GdImage
    {
        $info = @getimagesize($file->getRealPath());

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new InvalidArgumentException('Format non accepté.');
        }

        if ($info[0] < self::MIN_SIDE || $info[1] < self::MIN_SIDE || $info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw new InvalidArgumentException('Dimensions non acceptées.');
        }

        $image = $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($file->getRealPath()) : @imagecreatefromjpeg($file->getRealPath());

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('Image illisible.');
        }

        return $image;
    }
}
