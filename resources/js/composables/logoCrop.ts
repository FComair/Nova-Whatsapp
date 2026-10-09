/**
 * Recadrage du logo avant l'envoi : zoom et position de l'image dans une zone carrée, sans jamais la déformer.
 *
 * L'état est indépendant de la taille de l'écran : `zoom` (1 = l'image couvre exactement le carré ; plus petit : l'image entière
 * tient dans le carré avec des marges ; plus grand : on se rapproche) et `u`, `v` (le point de l'image, de 0 à 1, qui est au centre
 * du carré). Le même état sert à la zone de travail, aux aperçus (menu, profil, QR code) et au fichier envoyé.
 */

export const MAX_ZOOM = 4;

/** Côté du fichier envoyé : assez grand pour un affichage net à 256 px (le serveur en tire les 64 px et 256 px du menu et du profil). */
export const OUTPUT_SIZE = 512;

/** Mêmes règles que le serveur (OrganizationLogo, LogoRequest). */
export const LOGO_RULES = { maxBytes: 2 * 1024 * 1024, minSide: 200, maxSide: 6000 } as const;

export interface CropState {
    zoom: number;
    u: number;
    v: number;
}

export interface Size {
    width: number;
    height: number;
}

export interface Rect {
    left: number;
    top: number;
    width: number;
    height: number;
}

export const initialCrop = (): CropState => ({ zoom: 1, u: 0.5, v: 0.5 });

/** Zoom minimal : l'image entière tient dans le carré. */
export function minZoom(image: Size): number {
    return Math.min(image.width, image.height) / Math.max(image.width, image.height);
}

/** Échelle (pixels écran par pixel de l'image) : à zoom 1, le petit côté de l'image remplit exactement le carré. */
export function scaleOf(view: number, image: Size, zoom: number): number {
    return (view / Math.min(image.width, image.height)) * zoom;
}

/** Garde le centre sur l'image : jamais de vide d'un côté quand l'image est plus grande que le carré, centrée sinon. */
function clampAxis(center: number, displayed: number, view: number): number {
    if (displayed <= view) {
        return 0.5;
    }

    const margin = view / (2 * displayed);

    return Math.min(1 - margin, Math.max(margin, center));
}

export function clampCrop(state: CropState, view: number, image: Size): CropState {
    const zoom = Math.min(MAX_ZOOM, Math.max(minZoom(image), state.zoom));
    const scale = scaleOf(view, image, zoom);

    return { zoom, u: clampAxis(state.u, image.width * scale, view), v: clampAxis(state.v, image.height * scale, view) };
}

/** Position et taille de l'image dans le carré, en pixels du carré. */
export function placement(state: CropState, view: number, image: Size): Rect {
    const scale = scaleOf(view, image, state.zoom);
    const width = image.width * scale;
    const height = image.height * scale;

    return { left: view / 2 - state.u * width, top: view / 2 - state.v * height, width, height };
}

/** Déplace l'image de (dx, dy) pixels du carré : l'image suit le doigt ou la souris. */
export function panBy(state: CropState, dx: number, dy: number, view: number, image: Size): CropState {
    const scale = scaleOf(view, image, state.zoom);

    return clampCrop({ ...state, u: state.u - dx / (image.width * scale), v: state.v - dy / (image.height * scale) }, view, image);
}

/** Change le zoom en gardant le centre ; borné entre « image entière » et le zoom maximal. */
export function zoomTo(state: CropState, zoom: number, view: number, image: Size): CropState {
    // Arrondi au millième : pas de 1,2000000000000002 à force d'ajouter 0,1.
    return clampCrop({ ...state, zoom: Math.round(zoom * 1000) / 1000 }, view, image);
}

/** Même cadrage dans un carré de `size` pixels (aperçus) ou de la taille du fichier (export). */
export function scaledPlacement(state: CropState, view: number, image: Size, size: number): Rect {
    const rect = placement(state, view, image);
    const ratio = size / view;

    return { left: rect.left * ratio, top: rect.top * ratio, width: rect.width * ratio, height: rect.height * ratio };
}

/** Taille de l'image décodée par le navigateur, ou null si elle est illisible. */
export function loadImage(url: string): Promise<(Size & { element: HTMLImageElement }) | null> {
    return new Promise((resolve) => {
        const element = new Image();
        element.onload = () => resolve({ width: element.naturalWidth, height: element.naturalHeight, element });
        element.onerror = () => resolve(null);
        element.src = url;
    });
}

/**
 * Dessine le cadrage choisi dans un carré de `side` pixels et le renvoie en PNG. `background` remplit les marges quand on dézoome
 * (blanc pour un JPEG, transparent pour un PNG). Renvoie null si le navigateur ne peut pas produire l'image.
 */
export function renderCrop(
    source: CanvasImageSource,
    state: CropState,
    view: number,
    image: Size,
    side = OUTPUT_SIZE,
    background: string | null = null,
): Promise<Blob | null> {
    const canvas = document.createElement('canvas');
    canvas.width = side;
    canvas.height = side;
    const context = canvas.getContext('2d');

    if (context === null) {
        return Promise.resolve(null);
    }

    if (background !== null) {
        context.fillStyle = background;
        context.fillRect(0, 0, side, side);
    }

    const rect = scaledPlacement(state, view, image, side);
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.drawImage(source, rect.left, rect.top, rect.width, rect.height);

    return new Promise((resolve) => canvas.toBlob((blob) => resolve(blob), 'image/png'));
}
