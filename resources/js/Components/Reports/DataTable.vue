<script setup lang="ts">
import { computed, ref } from 'vue';
import { useT } from '../../composables/useT';

/**
 * Tableau accessible : légende, en-têtes de colonne et de ligne, défilement horizontal au clavier sur petit écran, en-têtes
 * répétés sur chaque page à l'impression. Les cellules arrivent déjà mises en forme ; `sort` (valeur brute) permet le tri.
 */
export interface TableColumn {
    key: string;
    label: string;
    align?: 'left' | 'right';
    sortable?: boolean;
}
export type TableRow = Record<string, string | number | null | { text: string; sort: number }>;

const props = defineProps<{ caption: string; columns: TableColumn[]; rows: TableRow[]; totals?: TableRow }>();

const { t } = useT();
const sortKey = ref<string | null>(null);
const descending = ref(false);

const text = (cell: TableRow[string] | undefined): string => (cell === null || cell === undefined ? '—' : typeof cell === 'object' ? cell.text : String(cell));
const raw = (cell: TableRow[string] | undefined): number | string =>
    cell === null || cell === undefined ? -Infinity : typeof cell === 'object' ? cell.sort : cell;

const sorted = computed(() => {
    const key = sortKey.value;

    if (key === null) {
        return props.rows;
    }

    return [...props.rows].sort((a, b) => {
        const left = raw(a[key]);
        const right = raw(b[key]);
        const order = typeof left === 'number' && typeof right === 'number' ? left - right : String(left).localeCompare(String(right));

        return descending.value ? -order : order;
    });
});

function sortBy(key: string): void {
    descending.value = sortKey.value === key ? !descending.value : false;
    sortKey.value = key;
}
</script>

<template>
    <div
        class="overflow-x-auto rounded-control border border-line print:overflow-visible print:border-0"
        tabindex="0"
        role="region"
        :aria-label="caption"
        data-testid="data-table"
    >
        <table class="w-full min-w-[28rem] border-collapse text-sm">
            <caption class="sr-only">
                {{
                    caption
                }}
            </caption>
            <thead class="bg-surface-2 text-left text-xs font-semibold text-neutral-800 print:table-header-group">
                <tr>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        class="px-3 py-2"
                        :class="column.align === 'right' ? 'text-right' : 'text-left'"
                        :aria-sort="sortKey === column.key ? (descending ? 'descending' : 'ascending') : undefined"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            class="inline-flex items-center gap-1 font-semibold hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600/20 print:pointer-events-none"
                            :title="t('reports.practitioner.sort', { column: column.label })"
                            @click="sortBy(column.key)"
                        >
                            {{ column.label }}
                            <span aria-hidden="true" class="text-[0.6rem] print:hidden">{{ sortKey === column.key ? (descending ? '▼' : '▲') : '↕' }}</span>
                        </button>
                        <template v-else>{{ column.label }}</template>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                <tr v-for="(row, rowIndex) in sorted" :key="rowIndex" class="print:break-inside-avoid">
                    <template v-for="(column, columnIndex) in columns" :key="column.key">
                        <th v-if="columnIndex === 0" scope="row" class="px-3 py-2 text-left font-medium text-neutral-900">{{ text(row[column.key]) }}</th>
                        <td v-else class="px-3 py-2 text-neutral-900" :class="column.align === 'right' ? 'text-right tabular-nums' : 'text-left'">
                            {{ text(row[column.key]) }}
                        </td>
                    </template>
                </tr>
            </tbody>
            <tfoot v-if="totals" class="bg-surface-2 font-semibold">
                <tr>
                    <th
                        v-for="(column, columnIndex) in columns"
                        :key="column.key"
                        :scope="columnIndex === 0 ? 'row' : undefined"
                        class="px-3 py-2"
                        :class="column.align === 'right' ? 'text-right tabular-nums' : 'text-left'"
                    >
                        {{ text(totals[column.key]) }}
                    </th>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
