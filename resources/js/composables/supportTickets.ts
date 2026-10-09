/** Aides du support : couleurs des états, tailles de fichiers, validation des pièces jointes. Pures et testées. */

export type TicketStatus = 'open' | 'in_progress' | 'waiting_on_you' | 'resolved' | 'closed';

export type BadgeVariant = 'success' | 'warning' | 'danger' | 'info' | 'neutral';

export const STATUSES: TicketStatus[] = ['open', 'in_progress', 'waiting_on_you', 'resolved', 'closed'];

/** Jamais la couleur seule : le badge porte aussi une icône et le libellé de l'état. */
export function statusVariant(status: string): BadgeVariant {
    return (
        ({ open: 'neutral', in_progress: 'info', waiting_on_you: 'warning', resolved: 'success', closed: 'neutral' } as Record<string, BadgeVariant>)[status] ??
        'neutral'
    );
}

export function urgencyVariant(urgency: string): BadgeVariant {
    return ({ low: 'neutral', medium: 'info', high: 'danger' } as Record<string, BadgeVariant>)[urgency] ?? 'neutral';
}

export const ALLOWED_TYPES = ['image/png', 'image/jpeg', 'application/pdf'];

export interface AttachmentLimits {
    files: number;
    kilobytes: number;
    types: string[];
}

export type AttachmentProblem = { code: 'too_many' | 'too_big' | 'bad_type'; name: string } | null;

/**
 * Ajoute des fichiers à la sélection en respectant les limites : au plus N fichiers, une taille maximale, PNG, JPG ou PDF.
 * Les fichiers refusés ne sont pas ajoutés ; le premier problème rencontré est rapporté.
 */
export function addFiles(current: File[], incoming: File[], limits: AttachmentLimits): { files: File[]; problem: AttachmentProblem } {
    const files = [...current];
    let problem: AttachmentProblem = null;

    for (const file of incoming) {
        if (files.length >= limits.files) {
            problem ??= { code: 'too_many', name: file.name };
            break;
        }

        if (file.size > limits.kilobytes * 1024) {
            problem ??= { code: 'too_big', name: file.name };
            continue;
        }

        if (!ALLOWED_TYPES.includes(file.type)) {
            problem ??= { code: 'bad_type', name: file.name };
            continue;
        }

        files.push(file);
    }

    return { files, problem };
}

/** « 842 Ko », « 1,3 Mo » : taille lisible. */
export function formatSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
