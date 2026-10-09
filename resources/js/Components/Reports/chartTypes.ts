export interface SeriesDef {
    key: string;
    label: string;
    color: string;
    pattern: 'solid' | 'stripes' | 'dots' | 'cross';
}

/** Un groupe de barres (un jour, un service…) : une valeur par série. */
export interface BarItem {
    key: string;
    /** Étiquette courte sous l'axe. */
    label: string;
    /** Libellé complet pour l'info-bulle et la description. */
    title: string;
    values: number[];
}
