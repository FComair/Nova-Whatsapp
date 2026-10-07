import { describe, expect, it } from 'vitest';
import { distance, highlight, normalize, searchEntries, stem, type Localized, type SearchEntry } from './settingsSearch';

function entry(
    id: string,
    title: Localized,
    keywords: Partial<Localized> = {},
    description: Partial<Localized> | null = null,
    group = 'integrations',
): SearchEntry {
    return {
        id,
        group,
        page: id,
        href: `/${id}`,
        anchor: null,
        title,
        description: description === null ? null : { fr: '', nl: '', en: '', ...description },
        keywords: { fr: '', nl: '', en: '', ...keywords },
    };
}

const calendars = entry(
    'calendars',
    { fr: 'Calendriers', nl: 'Kalenders', en: 'Calendars' },
    { fr: 'agenda agendas', nl: 'agenda kalender', en: 'agenda sync' },
);
const team = entry(
    'team',
    { fr: 'Équipe et services', nl: 'Team en diensten', en: 'Services & team' },
    { fr: 'employés personnel', nl: 'medewerkers', en: 'employees staff' },
    null,
    'setup',
);
const security = entry(
    'security',
    { fr: 'Sécurité', nl: 'Beveiliging', en: 'Security' },
    { en: '2fa password' },
    { en: 'Manage your password and sessions' },
    'privacy',
);
const entries = [calendars, team, security];

const ids = (query: string, locale: 'fr' | 'nl' | 'en' = 'en') => searchEntries(entries, query, locale).map((result) => result.entry.id);

describe('normalisation', () => {
    it('retire accents, casse et ponctuation', () => {
        expect(normalize('  Équipe & Services! ')).toBe('equipe services');
        expect(normalize("L'agenda-test")).toBe('l agenda test');
    });

    it('rapproche singulier, pluriel et accents dans les trois langues', () => {
        expect(stem(normalize('employé'))).toBe(stem(normalize('employees')));
        expect(stem(normalize('employés'))).toBe(stem(normalize('employee')));
        expect(stem('calendars')).toBe(stem('calendar'));
        expect(stem('diensten')).toBe(stem('dienst'));
    });
});

describe('distance', () => {
    it('compte une faute, un échange de lettres voisines et s’arrête au-delà du maximum', () => {
        expect(distance('security', 'securitx', 2)).toBe(1);
        expect(distance('calendar', 'calednar', 2)).toBe(1);
        expect(distance('abc', 'xyzxyz', 1)).toBeGreaterThan(1);
    });
});

describe('recherche multilingue', () => {
    it('trouve la même page en tapant dans n’importe quelle langue, quelle que soit la langue de l’interface', () => {
        for (const query of ['calendar', 'kalender', 'calendrier', 'agenda']) {
            expect(ids(query, 'en')).toEqual(['calendars']);
            expect(ids(query, 'fr')).toEqual(['calendars']);
            expect(ids(query, 'nl')).toEqual(['calendars']);
        }
    });

    it('trouve les mots-clés des autres langues (employés → équipe)', () => {
        expect(ids('employés')).toEqual(['team']);
        expect(ids('medewerkers', 'fr')).toEqual(['team']);
        expect(ids('employee')).toEqual(['team']);
    });

    it('signale la langue et le mot quand la correspondance vient d’une autre langue que celle de l’interface', () => {
        const [result] = searchEntries(entries, 'calendrier', 'en');

        expect(result?.entry.id).toBe('calendars');
        expect(result?.matchedWord).not.toBeNull();

        const same = searchEntries(entries, 'calendar', 'en')[0];
        expect(same?.matchedWord).toBeNull();
    });

    it('tolère une faute de frappe à partir de cinq lettres seulement', () => {
        expect(ids('securty')).toEqual(['security']);
        expect(ids('calednar')).toEqual(['calendars']);
        expect(ids('sxc')).toEqual([]);
    });

    it('cherche aussi dans les descriptions', () => {
        expect(ids('sessions')).toEqual(['security']);
    });

    it('exige tous les mots de la requête', () => {
        expect(ids('password sessions')).toEqual(['security']);
        expect(ids('password calendar')).toEqual([]);
    });

    it('ne cherche pas sous deux caractères et répond vide sans correspondance', () => {
        expect(ids('c')).toEqual([]);
        expect(ids('zxqv')).toEqual([]);
    });
});

describe('classement', () => {
    const pages = [
        entry('b', { fr: 'Sécurité', nl: 'Beveiliging', en: 'Security keys' }, {}, null, 'privacy'),
        entry('a', { fr: 'Sécurité', nl: 'Beveiliging', en: 'Security' }, {}, null, 'privacy'),
        entry('c', { fr: 'Autre', nl: 'Ander', en: 'Other' }, { en: 'security' }, null, 'privacy'),
        entry('d', { fr: 'Autre', nl: 'Ander', en: 'Account' }, {}, { en: 'Your security settings' }, 'privacy'),
    ];

    it('place le titre exact avant le titre qui commence par la requête, puis le mot-clé, puis la description', () => {
        expect(searchEntries(pages, 'security', 'en').map((result) => result.entry.id)).toEqual(['a', 'b', 'c', 'd']);
    });

    it('trie à égalité par ordre alphabétique des titres', () => {
        const same = [entry('z', { fr: 'Zèbre', nl: 'Zebra', en: 'Zebra calendar' }), entry('m', { fr: 'Mouton', nl: 'Schaap', en: 'Mule calendar' })];

        expect(searchEntries(same, 'calendar', 'en').map((result) => result.entry.id)).toEqual(['m', 'z']);
    });

    it('limite le nombre de résultats et les regroupe selon le menu', () => {
        const many = Array.from({ length: 12 }, (_, index) =>
            entry(`e${index}`, { fr: `Réglage ${index}`, nl: `Instelling ${index}`, en: `Setting ${index}` }, {}, null, index % 2 === 0 ? 'privacy' : 'setup'),
        );
        const found = searchEntries(many, 'setting', 'en');

        expect(found).toHaveLength(8);

        const groups = found.map((result) => result.entry.group);
        expect(groups.join(',')).toMatch(/^(privacy,)*(setup,?)*$|^(setup,)*(privacy,?)*$/);
    });
});

describe('mise en évidence', () => {
    it('entoure les mots trouvés, sans tenir compte des accents ni de la casse', () => {
        expect(highlight('Équipe et services', 'equipe')).toEqual([
            { text: 'Équipe', hit: true },
            { text: ' et services', hit: false },
        ]);
    });

    it('met en évidence le début du mot pour un préfixe', () => {
        expect(highlight('Calendars', 'cal')).toEqual([
            { text: 'Cal', hit: true },
            { text: 'endars', hit: false },
        ]);
    });

    it('met en évidence le mot entier pour un pluriel ou une faute', () => {
        expect(highlight('Calendars', 'calendar')).toEqual([{ text: 'Calendars', hit: true }]);
        expect(highlight('Security', 'securty')).toEqual([{ text: 'Security', hit: true }]);
    });

    it('rend le texte intact sans correspondance', () => {
        expect(highlight('Hello', 'xyz')).toEqual([{ text: 'Hello', hit: false }]);
    });
});
