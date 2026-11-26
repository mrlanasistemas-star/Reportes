<script setup lang="ts">
/**
 * PeriodRail — navegador de periodos (sin calendarios ni selects).
 *
 * - Flechas ‹ › a los lados del periodo actual: ‹ = anterior (más antiguo),
 *   › = siguiente (más reciente). Se mueven dentro del MISMO tipo de periodo.
 * - Teclado: ← → en cualquier parte de la pantalla (salvo al escribir).
 * - Móvil: deslizar el dedo sobre el periodo actual hacia la izquierda/derecha.
 * - Debajo, carril cronológico (antiguo → reciente) para saltar con un toque.
 * El nombre del periodo entra deslizándose en la dirección del cambio.
 */
import {
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    ChevronsRight,
    LoaderCircle,
} from 'lucide-vue-next';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import type { HardDataPeriod } from '@/types/hardData';

const props = defineProps<{
    periods: HardDataPeriod[];
    modelValue: number | null;
    loading?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [id: number] }>();

const TYPE_LABELS: Record<string, string> = {
    monthly: 'Mensual',
    weekly: 'Semanal',
    bimonthly: 'Bimestral',
    quarterly: 'Trimestral',
};
const TYPE_ORDER = ['monthly', 'weekly', 'bimonthly', 'quarterly'];

const current = computed(
    () => props.periods.find((p) => p.id === props.modelValue) ?? null,
);
const types = computed(() =>
    [...new Set(props.periods.map((p) => p.type))].sort(
        (a, b) => TYPE_ORDER.indexOf(a) - TYPE_ORDER.indexOf(b),
    ),
);
const activeType = ref<string>(
    current.value?.type ?? types.value[0] ?? 'monthly',
);
watch(current, (p) => p && (activeType.value = p.type));

/** Periodos del tipo activo, del más antiguo al más reciente (izquierda → derecha). */
const line = computed(() =>
    props.periods
        .filter((p) => p.type === activeType.value)
        .slice()
        .reverse(),
);
const index = computed(() =>
    line.value.findIndex((p) => p.id === props.modelValue),
);
const prev = computed(() =>
    index.value > 0 ? line.value[index.value - 1] : null,
);
const next = computed(() =>
    index.value >= 0 && index.value < line.value.length - 1
        ? line.value[index.value + 1]
        : null,
);
const latest = computed(() => line.value[line.value.length - 1] ?? null);
const progress = computed(() =>
    line.value.length > 1
        ? (Math.max(index.value, 0) / (line.value.length - 1)) * 100
        : 100,
);

/** Dirección de la animación: 1 = hacia adelante (más reciente), -1 = hacia atrás. */
const direction = ref<1 | -1>(1);
const bump = ref(0);

function select(id: number): void {
    if (id === props.modelValue) {
        return;
    }

    const to = line.value.findIndex((p) => p.id === id);
    direction.value = to >= index.value ? 1 : -1;
    emit('update:modelValue', id);
}

function step(delta: 1 | -1): void {
    const target = delta === 1 ? next.value : prev.value;

    if (target) {
        select(target.id);
    } else {
        // Ya está en la orilla: un pequeño «rebote» avisa que no hay más.
        bump.value = delta;
        setTimeout(() => (bump.value = 0), 320);
    }
}

function pickType(type: string): void {
    if (type === activeType.value) {
        return;
    }

    activeType.value = type;
    const newest = props.periods.find((p) => p.type === type);

    if (newest) {
        direction.value = 1;
        emit('update:modelValue', newest.id);
    }
}

// ── Teclado global ← → ───────────────────────────────────────────────────────
function onWindowKey(e: KeyboardEvent): void {
    if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
        return;
    }

    if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) {
        return;
    }

    const el = e.target instanceof Element ? e.target : null;

    if (
        el?.closest(
            'input, textarea, select, [contenteditable="true"], [role="dialog"], [role="listbox"], [role="slider"]',
        )
    ) {
        return;
    }

    e.preventDefault();
    step(e.key === 'ArrowRight' ? 1 : -1);
}

onMounted(() => window.addEventListener('keydown', onWindowKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onWindowKey));

// ── Deslizar con el dedo ─────────────────────────────────────────────────────
let touchX: number | null = null;
let touchY = 0;

function onTouchStart(e: TouchEvent): void {
    touchX = e.touches[0].clientX;
    touchY = e.touches[0].clientY;
}

function onTouchEnd(e: TouchEvent): void {
    if (touchX === null) {
        return;
    }

    const dx = e.changedTouches[0].clientX - touchX;
    const dy = e.changedTouches[0].clientY - touchY;
    touchX = null;

    if (Math.abs(dx) > 48 && Math.abs(dx) > Math.abs(dy) * 1.5) {
        // Deslizar a la izquierda = avanzar (como pasar página).
        step(dx < 0 ? 1 : -1);
    }
}

// ── Carril: centrar el periodo activo ────────────────────────────────────────
const rail = ref<HTMLElement | null>(null);
const canScrollLeft = ref(false);
const canScrollRight = ref(false);

function updateEdges(): void {
    const el = rail.value;

    if (!el) {
        return;
    }

    canScrollLeft.value = el.scrollLeft > 4;
    canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4;
}

function centerActive(smooth = true): void {
    nextTick(() => {
        rail.value
            ?.querySelector<HTMLElement>(`[data-period="${props.modelValue}"]`)
            ?.scrollIntoView({
                block: 'nearest',
                inline: 'center',
                behavior: smooth ? 'smooth' : 'auto',
            });
        setTimeout(updateEdges, smooth ? 350 : 0);
    });
}

function scrollRail(dir: 1 | -1): void {
    rail.value?.scrollBy({
        left: dir * rail.value.clientWidth * 0.7,
        behavior: 'smooth',
    });
}

watch(
    () => props.modelValue,
    () => centerActive(),
);
watch(activeType, () => centerActive(false));
onMounted(() => {
    centerActive(false);
    window.addEventListener('resize', updateEdges);
});
onBeforeUnmount(() => window.removeEventListener('resize', updateEdges));
</script>

<template>
    <div class="space-y-3">
        <!-- Tipo de periodo (solo si hay más de uno) -->
        <div
            v-if="types.length > 1"
            class="flex w-fit gap-1 rounded-full border border-border bg-muted/60 p-1"
            role="tablist"
            aria-label="Tipo de periodo"
        >
            <button
                v-for="t in types"
                :key="t"
                type="button"
                role="tab"
                :aria-selected="t === activeType"
                :class="[
                    'rounded-full px-3 py-1 text-xs font-bold transition-all duration-200',
                    t === activeType
                        ? 'bg-card text-foreground shadow-soft-xs'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                @click="pickType(t)"
            >
                {{ TYPE_LABELS[t] ?? t }}
            </button>
        </div>

        <!-- Navegador principal: ‹ periodo › -->
        <div
            class="flex items-stretch gap-2 sm:gap-3"
            @touchstart.passive="onTouchStart"
            @touchend.passive="onTouchEnd"
        >
            <button
                type="button"
                :disabled="!prev"
                :aria-label="
                    prev
                        ? `Periodo anterior: ${prev.name}`
                        : 'No hay periodo anterior'
                "
                :title="prev ? `← ${prev.name}` : 'Es el periodo más antiguo'"
                class="group flex w-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-2xl border border-border bg-card text-foreground shadow-soft-xs transition-all duration-200 hover:-translate-x-0.5 hover:border-primary/50 hover:bg-primary hover:text-primary-foreground hover:shadow-soft-md active:scale-95 disabled:pointer-events-none disabled:opacity-35 sm:w-auto sm:flex-row sm:gap-2 sm:px-4"
                @click="step(-1)"
            >
                <ChevronLeft
                    class="size-5 transition-transform duration-200 group-hover:-translate-x-0.5"
                    aria-hidden="true"
                />
                <span
                    class="hidden max-w-[7rem] truncate text-xs font-bold md:inline"
                    >{{ prev?.name ?? 'Anterior' }}</span
                >
            </button>

            <div
                :class="[
                    'relative min-w-0 flex-1 overflow-hidden rounded-2xl bg-gradient-to-br from-primary via-primary/90 to-primary/70 px-4 py-3 text-primary-foreground shadow-soft-md transition-transform duration-300',
                    bump === 1
                        ? 'translate-x-1.5'
                        : bump === -1
                          ? '-translate-x-1.5'
                          : '',
                ]"
                aria-live="polite"
            >
                <div
                    class="dd-shine pointer-events-none absolute inset-y-0 -left-1/3 w-1/3 bg-gradient-to-r from-transparent via-white/15 to-transparent"
                    aria-hidden="true"
                />
                <div
                    class="pointer-events-none absolute -top-12 -right-10 size-36 rounded-full bg-white/10 blur-2xl"
                    aria-hidden="true"
                />

                <div
                    class="relative flex flex-wrap items-center gap-x-3 gap-y-2"
                >
                    <span
                        class="hidden size-10 shrink-0 items-center justify-center rounded-xl bg-white/15 sm:flex"
                        aria-hidden="true"
                    >
                        <LoaderCircle
                            v-if="loading"
                            class="size-5 animate-spin"
                        />
                        <CalendarRange v-else class="size-5" />
                    </span>

                    <div class="relative min-w-0 flex-1">
                        <Transition
                            mode="out-in"
                            :enter-from-class="
                                direction === 1
                                    ? 'translate-x-6 opacity-0'
                                    : '-translate-x-6 opacity-0'
                            "
                            :leave-to-class="
                                direction === 1
                                    ? '-translate-x-6 opacity-0'
                                    : 'translate-x-6 opacity-0'
                            "
                            enter-active-class="transition duration-300 ease-out"
                            leave-active-class="transition duration-150 ease-in"
                        >
                            <div :key="current?.id ?? 0" class="min-w-0">
                                <p
                                    class="truncate text-lg leading-tight font-extrabold tracking-tight sm:text-xl"
                                >
                                    {{ current?.name ?? 'Elige un periodo' }}
                                </p>
                                <p
                                    class="mt-0.5 truncate text-xs text-primary-foreground/80"
                                >
                                    {{ current?.range ?? '—' }}
                                </p>
                            </div>
                        </Transition>
                    </div>

                    <div
                        class="flex shrink-0 basis-full items-center justify-between gap-1 sm:basis-auto sm:flex-col sm:items-end"
                    >
                        <span
                            class="rounded-full bg-white/20 px-2 py-0.5 text-[10px] font-bold tabular-nums"
                            >{{ Math.max(index, 0) + 1 }} de
                            {{ line.length }}</span
                        >
                        <button
                            v-if="latest && latest.id !== modelValue"
                            type="button"
                            class="inline-flex items-center gap-0.5 rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-primary transition hover:scale-105"
                            title="Ir al periodo más reciente"
                            @click="select(latest.id)"
                        >
                            Más reciente
                            <ChevronsRight class="size-3" aria-hidden="true" />
                        </button>
                    </div>
                </div>

                <!-- Avance en la línea de tiempo -->
                <div
                    class="relative mt-2.5 h-1 overflow-hidden rounded-full bg-white/20"
                >
                    <div
                        class="h-full rounded-full bg-white transition-[width] duration-500 ease-out"
                        :style="{ width: `${progress}%` }"
                    />
                </div>
            </div>

            <button
                type="button"
                :disabled="!next"
                :aria-label="
                    next
                        ? `Periodo siguiente: ${next.name}`
                        : 'No hay periodo siguiente'
                "
                :title="next ? `${next.name} →` : 'Es el periodo más reciente'"
                class="group flex w-10 shrink-0 flex-col items-center justify-center gap-0.5 rounded-2xl border border-border bg-card text-foreground shadow-soft-xs transition-all duration-200 hover:translate-x-0.5 hover:border-primary/50 hover:bg-primary hover:text-primary-foreground hover:shadow-soft-md active:scale-95 disabled:pointer-events-none disabled:opacity-35 sm:w-auto sm:flex-row-reverse sm:gap-2 sm:px-4"
                @click="step(1)"
            >
                <ChevronRight
                    class="size-5 transition-transform duration-200 group-hover:translate-x-0.5"
                    aria-hidden="true"
                />
                <span
                    class="hidden max-w-[7rem] truncate text-xs font-bold md:inline"
                    >{{ next?.name ?? 'Siguiente' }}</span
                >
            </button>
        </div>

        <!-- Carril cronológico para saltar directo -->
        <div v-if="line.length > 1" class="relative">
            <button
                v-show="canScrollLeft"
                type="button"
                class="absolute top-1/2 left-0 z-10 hidden size-7 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-card shadow-soft-sm transition hover:scale-110 sm:flex"
                aria-label="Ver periodos más antiguos"
                @click="scrollRail(-1)"
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
            </button>
            <div
                ref="rail"
                role="radiogroup"
                aria-label="Saltar a un periodo"
                :class="[
                    'flex snap-x gap-1.5 overflow-x-auto px-0.5 py-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
                    canScrollLeft && canScrollRight
                        ? '[mask-image:linear-gradient(90deg,transparent,#000_2.5rem,#000_calc(100%-2.5rem),transparent)]'
                        : canScrollLeft
                          ? '[mask-image:linear-gradient(90deg,transparent,#000_2.5rem)]'
                          : canScrollRight
                            ? '[mask-image:linear-gradient(90deg,#000_calc(100%-2.5rem),transparent)]'
                            : '',
                ]"
                @scroll.passive="updateEdges"
            >
                <button
                    v-for="p in line"
                    :key="p.id"
                    type="button"
                    role="radio"
                    :data-period="p.id"
                    :aria-checked="p.id === modelValue"
                    :title="p.range ?? p.name"
                    :class="[
                        'shrink-0 snap-center rounded-full border px-3 py-1 text-xs font-semibold whitespace-nowrap transition-all duration-200 outline-none focus-visible:ring-2 focus-visible:ring-ring',
                        p.id === modelValue
                            ? 'scale-105 border-primary bg-primary/10 text-primary shadow-soft-xs'
                            : 'border-border bg-card text-muted-foreground hover:-translate-y-0.5 hover:border-primary/40 hover:text-foreground',
                    ]"
                    @click="select(p.id)"
                >
                    {{ p.name }}
                </button>
            </div>
            <button
                v-show="canScrollRight"
                type="button"
                class="absolute top-1/2 right-0 z-10 hidden size-7 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-card shadow-soft-sm transition hover:scale-110 sm:flex"
                aria-label="Ver periodos más recientes"
                @click="scrollRail(1)"
            >
                <ChevronRight class="size-4" aria-hidden="true" />
            </button>
        </div>

        <p class="hidden text-[11px] text-muted-foreground lg:block">
            Tip: usa las flechas
            <kbd class="rounded border border-border bg-muted px-1 font-mono"
                >←</kbd
            >
            <kbd class="rounded border border-border bg-muted px-1 font-mono"
                >→</kbd
            >
            del teclado para cambiar de periodo.
        </p>
    </div>
</template>

<style scoped>
.dd-shine {
    animation: dd-shine 5.5s var(--ease-in-out, ease-in-out) infinite;
}

@keyframes dd-shine {
    0%,
    70% {
        transform: translateX(0);
    }

    100% {
        transform: translateX(450%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .dd-shine {
        animation: none;
    }
}
</style>
