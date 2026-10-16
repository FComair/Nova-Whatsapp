<script setup lang="ts">
import { computed, onMounted, ref, useId } from 'vue';
import {
    type CropState,
    LOGO_RULES,
    MAX_ZOOM,
    OUTPUT_SIZE,
    clampCrop,
    initialCrop,
    minZoom,
    panBy,
    placement,
    renderCrop,
    scaledPlacement,
    zoomTo,
} from '../composables/logoCrop';
import { useT } from '../composables/useT';
import BaseButton from './BaseButton.vue';

/**
 * Recadrage du logo : on glisse l'image (souris, doigt, flèches) et on zoome (curseur, + / −, pincement) dans une zone carrée,
 * avec un cercle-guide (le logo s'affiche en rond dans le menu et le profil) et des aperçus en direct dans le menu, le profil et au
 * centre du QR code. L'image n'est jamais déformée : même échelle sur les deux axes. Rien n'est envoyé tant qu'on n'a pas enregistré.
 */
const props = defineProps<{
    src: string;
    width: number;
    height: number;
    /** Nom de l'établissement, pour l'aperçu du menu. */
    name: string;
    /** JPEG : les marges laissées en dézoomant sont blanches (un PNG garde sa transparence). */
    opaque: boolean;
    processing?: boolean;
    progress?: number | null;
}>();
const emit = defineEmits<{ save: [file: File]; cancel: []; fail: [] }>();

const { t } = useT();
const id = useId();
const image = computed(() => ({ width: props.width, height: props.height }));
const crop = ref<CropState>(initialCrop());
const viewport = ref<HTMLElement | null>(null);
const picture = ref<HTMLImageElement | null>(null);
const side = ref(288);
const preparing = ref(false);

onMounted(() => {
    const measure = (): void => {
        side.value = viewport.value?.clientWidth || 288;
        crop.value = clampCrop(crop.value, side.value, image.value);
    };

    measure();

    if (typeof ResizeObserver !== 'undefined' && viewport.value) {
        new ResizeObserver(measure).observe(viewport.value);
    }
});

const floor = computed(() => minZoom(image.value));
const rect = computed(() => placement(crop.value, side.value, image.value));
const percent = computed(() => Math.round(crop.value.zoom * 100));
const pictureStyle = computed(() => ({
    left: `${rect.value.left}px`,
    top: `${rect.value.top}px`,
    width: `${rect.value.width}px`,
    height: `${rect.value.height}px`,
}));
const atFloor = computed(() => crop.value.zoom <= floor.value + 0.001);
const atCeiling = computed(() => crop.value.zoom >= MAX_ZOOM - 0.001);
const pristine = computed(() => crop.value.zoom === 1 && crop.value.u === 0.5 && crop.value.v === 0.5);

function setZoom(zoom: number): void {
    crop.value = zoomTo(crop.value, zoom, side.value, image.value);
}

function reset(): void {
    crop.value = clampCrop(initialCrop(), side.value, image.value);
}

// --- Glisser (souris ou doigt) et pincer (deux doigts)
const pointers = new Map<number, { x: number; y: number }>();
let pinch = 0;

function distance(): number {
    const [a, b] = [...pointers.values()];

    return Math.hypot(a!.x - b!.x, a!.y - b!.y);
}

function down(event: PointerEvent): void {
    (event.currentTarget as HTMLElement).setPointerCapture?.(event.pointerId);
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
    pinch = pointers.size === 2 ? distance() : 0;
}

function move(event: PointerEvent): void {
    const last = pointers.get(event.pointerId);

    if (!last) {
        return;
    }

    const dx = event.clientX - last.x;
    const dy = event.clientY - last.y;
    pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

    if (pointers.size === 2) {
        const now = distance();

        if (pinch > 0) {
            setZoom(crop.value.zoom * (now / pinch));
        }

        pinch = now;

        return;
    }

    crop.value = panBy(crop.value, dx, dy, side.value, image.value);
}

function up(event: PointerEvent): void {
    pointers.delete(event.pointerId);
    pinch = pointers.size === 2 ? distance() : 0;
}

// Ctrl/⌘ + molette (et le pincement du pavé tactile) zoome ; la molette seule fait défiler la page.
function wheel(event: WheelEvent): void {
    if (event.ctrlKey || event.metaKey) {
        event.preventDefault();
        setZoom(crop.value.zoom * Math.exp(-event.deltaY * 0.01));
    }
}

// --- Clavier : flèches (Maj : plus vite), + / −, 0
function key(event: KeyboardEvent): void {
    const step = side.value * (event.shiftKey ? 0.1 : 0.02);
    const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };

    if (moves[event.key]) {
        event.preventDefault();
        crop.value = panBy(crop.value, ...moves[event.key]!, side.value, image.value);
    } else if (event.key === '+' || event.key === '=') {
        event.preventDefault();
        setZoom(crop.value.zoom + 0.1);
    } else if (event.key === '-' || event.key === '_') {
        event.preventDefault();
        setZoom(crop.value.zoom - 0.1);
    } else if (event.key === '0') {
        event.preventDefault();
        reset();
    }
}

// --- Enregistrement : le cadrage choisi devient un PNG carré ; on réduit la taille s'il dépassait la limite d'envoi.
async function save(): Promise<void> {
    if (!picture.value) {
        return;
    }

    preparing.value = true;

    try {
        for (const output of [OUTPUT_SIZE, 384, 256]) {
            const blob = await renderCrop(picture.value, crop.value, side.value, image.value, output, props.opaque ? '#ffffff' : null);

            if (blob === null) {
                break;
            }

            if (blob.size <= LOGO_RULES.maxBytes) {
                emit('save', new File([blob], 'logo.png', { type: 'image/png' }));

                return;
            }
        }

        emit('fail');
    } catch {
        emit('fail');
    } finally {
        preparing.value = false;
    }
}

// --- Aperçus : même cadrage, à la taille d'affichage réelle.
const previews = computed(() => [
    { key: 'menu', size: 32, round: true },
    { key: 'profile', size: 88, round: true },
    { key: 'qr', size: 56, round: false },
]);
const previewStyle = (size: number) => {
    const placed = scaledPlacement(crop.value, side.value, image.value, size);

    return { left: `${placed.left}px`, top: `${placed.top}px`, width: `${placed.width}px`, height: `${placed.height}px` };
};
</script>

<template>
    <div class="space-y-4" data-testid="logo-cropper">
        <div>
            <h3 class="text-base font-semibold text-nova-900">{{ t('logo.crop.title') }}</h3>
            <p class="mt-1 text-sm text-neutral-700">{{ t('logo.crop.help') }}</p>
            <p :id="`${id}-keys`" class="mt-1 text-xs text-neutral-600">{{ t('logo.crop.keyboard') }}</p>
        </div>

        <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
            <div
                ref="viewport"
                tabindex="0"
                role="group"
                :aria-label="t('logo.crop.viewport')"
                :aria-describedby="`${id}-keys`"
                class="relative aspect-square w-full max-w-72 shrink-0 cursor-grab touch-none select-none overflow-hidden rounded-xl bg-white ring-1 ring-line-strong focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600/30 active:cursor-grabbing"
                data-testid="crop-viewport"
                @pointerdown="down"
                @pointermove="move"
                @pointerup="up"
                @pointercancel="up"
                @wheel="wheel"
                @keydown="key"
            >
                <img
                    ref="picture"
                    :src="src"
                    alt=""
                    draggable="false"
                    class="pointer-events-none absolute max-w-none"
                    :style="pictureStyle"
                    data-testid="crop-image"
                />
                <!-- Guide : le logo s'affiche en rond dans le menu et le profil ; l'extérieur du cercle est assombri. -->
                <div
                    class="pointer-events-none absolute inset-0"
                    style="background: radial-gradient(circle closest-side at 50% 50%, transparent calc(100% - 1px), rgb(11 43 46 / 0.55) 100%)"
                    aria-hidden="true"
                />
                <div class="pointer-events-none absolute inset-0 rounded-full border-2 border-white/90" aria-hidden="true" />
            </div>

            <div class="min-w-0 flex-1 space-y-5">
                <div>
                    <label :for="`${id}-zoom`" class="mb-1.5 flex items-center justify-between text-sm font-semibold text-neutral-900">
                        <span>{{ t('logo.crop.zoom') }}</span>
                        <span class="font-normal text-neutral-700" data-testid="zoom-value">{{ t('logo.crop.zoom_value', { percent }) }}</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="inline-flex size-11 shrink-0 items-center justify-center rounded-full border-[1.5px] border-line-strong bg-surface text-lg font-semibold text-neutral-900 transition hover:border-primary-600 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600/30 active:bg-primary-100 disabled:cursor-not-allowed disabled:border-transparent disabled:bg-neutral-300 disabled:text-neutral-500"
                            :aria-label="t('logo.crop.zoom_out')"
                            :disabled="atFloor || processing"
                            data-testid="zoom-out"
                            @click="setZoom(crop.zoom - 0.1)"
                        >
                            −
                        </button>
                        <input
                            :id="`${id}-zoom`"
                            type="range"
                            :min="floor"
                            :max="MAX_ZOOM"
                            step="0.01"
                            :value="crop.zoom"
                            :aria-valuetext="t('logo.crop.zoom_value', { percent })"
                            :disabled="processing"
                            class="min-w-0 flex-1 accent-primary-600"
                            data-testid="zoom-range"
                            @input="setZoom(Number(($event.target as HTMLInputElement).value))"
                        />
                        <button
                            type="button"
                            class="inline-flex size-11 shrink-0 items-center justify-center rounded-full border-[1.5px] border-line-strong bg-surface text-lg font-semibold text-neutral-900 transition hover:border-primary-600 hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-600/30 active:bg-primary-100 disabled:cursor-not-allowed disabled:border-transparent disabled:bg-neutral-300 disabled:text-neutral-500"
                            :aria-label="t('logo.crop.zoom_in')"
                            :disabled="atCeiling || processing"
                            data-testid="zoom-in"
                            @click="setZoom(crop.zoom + 0.1)"
                        >
                            +
                        </button>
                    </div>
                </div>

                <div>
                    <p class="mb-2 text-sm font-semibold text-neutral-900">{{ t('logo.crop.previews') }}</p>
                    <ul class="flex flex-wrap items-end gap-5" data-testid="crop-previews">
                        <li v-for="item in previews" :key="item.key" class="flex flex-col items-center gap-1.5 text-xs text-neutral-700">
                            <span class="flex items-center gap-2">
                                <span
                                    class="relative inline-block shrink-0 overflow-hidden bg-white ring-1 ring-neutral-200"
                                    :class="item.round ? 'rounded-full' : 'rounded-lg'"
                                    :style="{ width: `${item.size}px`, height: `${item.size}px` }"
                                    :data-testid="`preview-${item.key}`"
                                >
                                    <img :src="src" alt="" draggable="false" class="absolute max-w-none" :style="previewStyle(item.size)" />
                                </span>
                                <span v-if="item.key === 'menu'" class="max-w-[7rem] truncate text-sm font-medium text-neutral-700">{{ name }}</span>
                            </span>
                            {{ t(`logo.crop.${item.key}`) }}
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-2">
            <BaseButton :loading="processing || preparing" data-testid="crop-save" @click="save">
                {{ processing ? t('logo.uploading') : preparing ? t('logo.crop.processing') : t('logo.save') }}
            </BaseButton>
            <BaseButton variant="secondary" :disabled="pristine || processing || preparing" data-testid="crop-reset" @click="reset">
                {{ t('logo.crop.reset') }}
            </BaseButton>
            <BaseButton variant="secondary" :disabled="processing || preparing" data-testid="crop-cancel" @click="emit('cancel')">
                {{ t('logo.cancel') }}
            </BaseButton>
        </div>

        <div
            v-if="progress !== undefined && progress !== null"
            class="h-1.5 w-full overflow-hidden rounded-full bg-neutral-200"
            role="progressbar"
            :aria-valuenow="progress"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div class="h-full bg-primary-600" :style="{ width: `${progress}%` }" />
        </div>
    </div>
</template>
