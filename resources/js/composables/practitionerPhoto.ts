/**
 * Photo de profil d'un praticien : adresse de l'image, tailles d'avatar, contrôles et préparation côté navigateur.
 * Les fonctions pures sont testées (practitionerPhoto.test.ts) ; `prepareImage` utilise le canvas du navigateur.
 */

export const PHOTO = {
    maxBytes: 5 * 1024 * 1024,
    minSide: 200,
    maxSide: 6000,
    /** Côté maximal de l'image envoyée : le serveur n'en garde que 256 px, inutile d'envoyer plus. */
    outputSide: 1024,
    types: ['image/jpeg', 'image/png', 'image/webp'],
};

export type AvatarSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';

/** Taille d'affichage de chaque avatar, en pixels CSS. */
export const AVATAR_PIXELS: Record<AvatarSize, number> = { xs: 20, sm: 28, md: 36, lg: 56, xl: 96 };

/** Petites tailles : image de 64 px ; au-delà, 256 px (suffisant pour les écrans à haute densité). */
export function sourceSize(size: AvatarSize): 64 | 256 {
    return AVATAR_PIXELS[size] <= 28 ? 64 : 256;
}

/** Adresse de la photo ; la version change à chaque nouvel envoi, ce qui renouvelle le cache du navigateur. */
export function photoUrl(id: string, size: 64 | 256, version: number): string {
    return `/praticiens/${id}/photo/${size}?v=${version}`;
}

export type PhotoProblem = 'type' | 'too_large' | 'dimensions' | 'invalid';

export class PhotoError extends Error {
    constructor(readonly code: PhotoProblem) {
        super(code);
    }
}

/** Contrôle immédiat du fichier choisi (type et poids) ; le serveur revérifie tout. */
export function validatePhoto(file: { type: string; size: number }): PhotoProblem | null {
    if (!PHOTO.types.includes(file.type)) {
        return 'type';
    }

    return file.size > PHOTO.maxBytes ? 'too_large' : null;
}

/** Dimensions acceptées : de 200 à 6 000 pixels de chaque côté. */
export function validateDimensions(width: number, height: number): PhotoProblem | null {
    return width < PHOTO.minSide || height < PHOTO.minSide || width > PHOTO.maxSide || height > PHOTO.maxSide ? 'dimensions' : null;
}

/** Réduit pour tenir dans `max` pixels sans déformer ni agrandir. */
export function fitWithin(width: number, height: number, max: number): { width: number; height: number } {
    const scale = Math.min(1, max / Math.max(width, height));

    return { width: Math.max(1, Math.round(width * scale)), height: Math.max(1, Math.round(height * scale)) };
}

async function decode(file: File): Promise<{ source: CanvasImageSource; width: number; height: number; release: () => void }> {
    try {
        // L'orientation de la photo (téléphone tenu à la verticale) est appliquée au décodage.
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });

        return { source: bitmap, width: bitmap.width, height: bitmap.height, release: () => bitmap.close() };
    } catch {
        const url = URL.createObjectURL(file);

        return new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve({ source: image, width: image.naturalWidth, height: image.naturalHeight, release: () => URL.revokeObjectURL(url) });
            image.onerror = () => {
                URL.revokeObjectURL(url);
                reject(new PhotoError('invalid'));
            };
            image.src = url;
        });
    }
}

/**
 * Vérifie puis prépare la photo choisie : JPEG, PNG ou WebP lu par le navigateur, droit, puis réécrit en JPEG d'au plus
 * 1 024 px (fond blanc sous les transparences). Cela accepte aussi le WebP sans dépendre du serveur et allège l'envoi.
 *
 * @throws PhotoError type, poids, dimensions ou fichier illisible
 */
export async function prepareImage(file: File): Promise<File> {
    const problem = validatePhoto(file);

    if (problem) {
        throw new PhotoError(problem);
    }

    const decoded = await decode(file).catch((error: unknown) => {
        throw error instanceof PhotoError ? error : new PhotoError('invalid');
    });

    try {
        const bad = validateDimensions(decoded.width, decoded.height);

        if (bad) {
            throw new PhotoError(bad);
        }

        const { width, height } = fitWithin(decoded.width, decoded.height, PHOTO.outputSide);
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');

        if (context === null) {
            throw new PhotoError('invalid');
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(decoded.source, 0, 0, width, height);

        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));

        if (blob === null) {
            throw new PhotoError('invalid');
        }

        return new File([blob], 'photo.jpg', { type: 'image/jpeg' });
    } finally {
        decoded.release();
    }
}
