import { describe, expect, it } from 'vitest';
import { addFiles, formatSize, statusVariant, urgencyVariant } from './supportTickets';

const file = (name: string, size: number, type: string): File => new File([new Uint8Array(size)], name, { type });
const limits = { files: 3, kilobytes: 5120, types: ['PNG', 'JPG', 'PDF'] };

describe('supportTickets', () => {
    it('associe un état à une couleur', () => {
        expect(statusVariant('waiting_on_you')).toBe('warning');
        expect(statusVariant('resolved')).toBe('success');
        expect(statusVariant('in_progress')).toBe('info');
        expect(statusVariant('inconnu')).toBe('neutral');
        expect(urgencyVariant('high')).toBe('danger');
    });

    it('accepte PNG, JPG et PDF dans la limite de taille', () => {
        const result = addFiles([], [file('a.png', 1000, 'image/png'), file('b.jpg', 2000, 'image/jpeg'), file('c.pdf', 3000, 'application/pdf')], limits);

        expect(result.files).toHaveLength(3);
        expect(result.problem).toBeNull();
    });

    it("refuse un fichier trop gros ou d'un autre type, et garde les bons", () => {
        const big = addFiles([], [file('gros.pdf', 6 * 1024 * 1024, 'application/pdf'), file('ok.png', 10, 'image/png')], limits);
        expect(big.files.map((item) => item.name)).toEqual(['ok.png']);
        expect(big.problem).toEqual({ code: 'too_big', name: 'gros.pdf' });

        const type = addFiles([], [file('script.exe', 10, 'application/x-msdownload')], limits);
        expect(type.files).toHaveLength(0);
        expect(type.problem).toEqual({ code: 'bad_type', name: 'script.exe' });
    });

    it('limite le nombre de fichiers', () => {
        const result = addFiles(
            [file('1.png', 1, 'image/png'), file('2.png', 1, 'image/png')],
            [file('3.png', 1, 'image/png'), file('4.png', 1, 'image/png')],
            limits,
        );

        expect(result.files).toHaveLength(3);
        expect(result.problem).toEqual({ code: 'too_many', name: '4.png' });
    });

    it('affiche une taille lisible', () => {
        expect(formatSize(100)).toBe('1 KB');
        expect(formatSize(2048)).toBe('2 KB');
        expect(formatSize(1.5 * 1024 * 1024)).toBe('1.5 MB');
    });
});
