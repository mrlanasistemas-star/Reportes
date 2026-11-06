<script setup lang="ts">
import {
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    History,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import ErPickerSurface from './ErPickerSurface.vue';

export interface PeriodOption {
    id: number;
    code: string;
    label: string;
    start?: string;
    end?: string;
}

/** Estado del reporte de un periodo ya visto en esta pestaña (no se pide al servidor). */
export interface PeriodStatus {
    status: string;
    label: string;
    preliminary: boolean;
}

const props = withDefaults(
    defineProps<{
        /** Periodos mensuales, el más reciente primero. */
        periods: PeriodOption[];
        statuses?: Record<number, PeriodStatus>;
        loading?: boolean;
    }>(),
    { statuses: () => ({}), loading: false },
);

const periodId = defineModel<number>({ required: true });

const MONTHS = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
];
const SHORT = [
    'ene',
    'feb',
    'mar',
    'abr',
    'may',
    'jun',
    'jul',
    'ago',
    'sep',
    'oct',
    'nov',
    'dic',
];

interface Slot {
    year: number;
    month: number; // 0-11
}

function parseDate(iso?: string): Date | null {
    const m = iso?.match(/^(\d{4})-(\d{2})-(\d{2})/);

    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
}

/** Mes contable del periodo: del código M-AAAA-MM; si no, del fin del periodo. */
function slotOf(p: PeriodOption): Slot | null {
    const m = p.code.match(/(\d{4})-(\d{2})/);

    if (m) {
        return { year: +m[1], month: +m[2] - 1 };
    }

    const end = parseDate(p.end);

    return end ? { year: end.getFullYear(), month: end.getMonth() } : null;
}

const keyOf = (s: Slot) => `${s.year}-${s.month}`;

const bySlot = computed(() => {
    const map = new Map<string, PeriodOption>();

    for (const p of props.periods) {
        const s = slotOf(p);

        if (s && !map.has(keyOf(s))) {
            map.set(keyOf(s), p);
        }
    }

    return map;
});
const years = computed(() => {
    const ys = props.periods
        .map((p) => slotOf(p)?.year)
        .filter((y): y is number => typeof y === 'number');

    return ys.length
        ? { min: Math.min(...ys), max: Math.max(...ys) }
        : { min: new Date().getFullYear(), max: new Date().getFullYear() };
});

const index = computed(() =>
    props.periods.findIndex((p) => p.id === periodId.value),
);
const current = computed(() => props.periods[index.value] ?? null);
const currentSlot = computed(() =>
    current.value ? slotOf(current.value) : null,
);
const latest = computed(() => props.periods[0] ?? null);
/** Lista: el más reciente primero → "anterior" es el siguiente índice. */
const older = computed(() => props.periods[index.value + 1] ?? null);
const newer = computed(() =>
    index.value > 0 ? props.periods[index.value - 1] : null,
);

function rangeText(p: PeriodOption | null, long = false): string {
    const a = parseDate(p?.start);
    const b = parseDate(p?.end);

    if (!a || !b) {
        return '';
    }

    if (long) {
        const fmt = (d: Date) =>
            `${d.getDate()} de ${MONTHS[d.getMonth()].toLowerCase()}`;

        return `Del ${fmt(a)} al ${fmt(b)} de ${b.getFullYear()}`;
    }

    return `${a.getDate()} ${SHORT[a.getMonth()]} – ${b.getDate()} ${SHORT[b.getMonth()]}`;
}

function splitLabel(p: PeriodOption | null): { month: string; year: string } {
    const s = p ? slotOf(p) : null;

    if (!p || !s) {
        return { month: p?.label ?? 'Sin periodo', year: '' };
    }

    return { month: MONTHS[s.month], year: String(s.year) };
}

const triggerLabel = computed(() => splitLabel(current.value));

function step(target: PeriodOption | null): void {
    if (target) {
        periodId.value = target.id;
    }
}

/* ---------- Popover ---------- */
const open = ref(false);
const viewYear = ref(currentSlot.value?.year ?? years.value.max);
const focusMonth = ref(currentSlot.value?.month ?? 0);
const slideDir = ref<'next' | 'prev'>('next');
const gridEl = ref<HTMLElement | null>(null);

watch(open, (on) => {
    if (on) {
        viewYear.value = currentSlot.value?.year ?? years.value.max;
        focusMonth.value = currentSlot.value?.month ?? 0;
    }
});

const cells = computed(() =>
    MONTHS.map((name, month) => {
        const slot = { year: viewYear.value, month };
        const period = bySlot.value.get(keyOf(slot)) ?? null;
        const status = period ? props.statuses[period.id] : undefined;

        return {
            month,
            name,
            short: name.slice(0, 3),
            period,
            selected: !!period && period.id === periodId.value,
            latest: !!period && period.id === latest.value?.id,
            status,
        };
    }),
);
const rows = computed(() =>
    [0, 1, 2, 3].map((r) => cells.value.slice(r * 3, r * 3 + 3)),
);
/** Leyenda solo de los estados que sí aparecen en el año visible. */
const legend = computed(() => {
    const seen = new Map<string, string>();

    for (const c of cells.value) {
        if (c.status) {
            seen.set(dotCls(c.status), legendLabel(c.status));
        }
    }

    return [...seen].map(([cls, label]) => ({ cls, label }));
});

function legendLabel(s: PeriodStatus): string {
    return s.status === 'aprobado'
        ? 'Aprobado'
        : s.preliminary
          ? 'Preliminar'
          : s.label;
}

function dotCls(s?: PeriodStatus): string {
    if (!s) {
        return '';
    }

    return s.status === 'aprobado'
        ? 'bg-emerald-500'
        : s.preliminary
          ? 'bg-amber-500'
          : 'bg-sky-500';
}

function setYear(y: number): void {
    if (y < years.value.min || y > years.value.max || y === viewYear.value) {
        return;
    }

    slideDir.value = y > viewYear.value ? 'next' : 'prev';
    viewYear.value = y;
}

async function focusCell(): Promise<void> {
    await nextTick();
    pendingFocus = true;
    afterYearEnter();
}

/** Enfoca la celda activa en cuanto existe (tras la transición de año, si la hay). */
let pendingFocus = false;

function afterYearEnter(): void {
    if (!pendingFocus) {
        return;
    }

    const el = gridEl.value?.querySelector<HTMLElement>(
        `[data-year="${viewYear.value}"] [data-month="${focusMonth.value}"]`,
    );

    if (el) {
        el.focus({ preventScroll: true });
        pendingFocus = false;
    }
}

function onOpenFocus(e: Event): void {
    e.preventDefault();
    void focusCell();
}

function moveTo(year: number, month: number): void {
    let y = year;
    let m = month;

    while (m < 0) {
        m += 12;
        y--;
    }

    while (m > 11) {
        m -= 12;
        y++;
    }

    if (y < years.value.min || y > years.value.max) {
        return;
    }

    setYear(y);
    focusMonth.value = m;
    void focusCell();
}

function choose(c: (typeof cells.value)[number]): void {
    if (!c.period) {
        return;
    }

    periodId.value = c.period.id;
    open.value = false;
}

function onGridKey(e: KeyboardEvent): void {
    const y = viewYear.value;
    const m = focusMonth.value;
    const map: Record<string, () => void> = {
        ArrowLeft: () => moveTo(y, m - 1),
        ArrowRight: () => moveTo(y, m + 1),
        ArrowUp: () => moveTo(y, m - 3),
        ArrowDown: () => moveTo(y, m + 3),
        Home: () => moveTo(y, 0),
        End: () => moveTo(y, 11),
        PageUp: () => moveTo(y - 1, m),
        PageDown: () => moveTo(y + 1, m),
    };
    const fn = map[e.key];

    if (fn) {
        e.preventDefault();
        fn();
    }
}

function goLatest(): void {
    if (latest.value) {
        periodId.value = latest.value.id;
    }

    open.value = false;
}

const currentStatus = computed(() =>
    current.value ? props.statuses[current.value.id] : undefined,
);

const arrowCls =
    'grid w-9 shrink-0 place-items-center text-muted-foreground transition hover:bg-background hover:text-foreground focus-visible:z-10 focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-30 dark:hover:bg-white/5';
</script>

<template>
    <div
        role="group"
        aria-label="Periodo"
        class="flex h-12 min-w-0 items-stretch overflow-hidden rounded-xl bg-muted/55 ring-1 ring-border/70 ring-inset dark:bg-white/[0.035]"
        :aria-busy="loading || undefined"
    >
        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    :class="[arrowCls, 'rounded-l-xl']"
                    :disabled="!older"
                    :aria-label="
                        older
                            ? `Periodo anterior: ${older.label}`
                            : 'No hay un periodo anterior'
                    "
                    @click="step(older)"
                >
                    <ChevronLeft class="size-4" />
                </button>
            </TooltipTrigger>
            <TooltipContent v-if="older">{{ older.label }}</TooltipContent>
        </Tooltip>

        <ErPickerSurface
            v-model:open="open"
            title="Elegir periodo"
            description="Elige el mes que quieres consultar."
            align="start"
            content-class="w-[20.5rem]"
            @open-focus="onOpenFocus"
        >
            <template #trigger="{ toggle, open: isOpen }">
                <button
                    type="button"
                    class="group/trigger relative flex min-w-0 flex-1 items-center gap-2.5 border-x border-border/60 bg-background/70 px-3 text-left transition hover:bg-background focus-visible:z-10 focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none aria-expanded:bg-background dark:bg-white/[0.04] dark:hover:bg-white/[0.07]"
                    aria-haspopup="dialog"
                    :aria-expanded="isOpen"
                    :aria-label="`Periodo: ${current?.label ?? 'sin elegir'}. Cambiar periodo`"
                    @click="toggle"
                >
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-lg bg-emerald-600/10 text-emerald-700 ring-1 ring-emerald-600/15 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20"
                        aria-hidden="true"
                    >
                        <CalendarDays class="size-4" />
                    </span>
                    <span class="flex min-w-0 flex-1 flex-col leading-tight">
                        <span
                            class="truncate text-[0.9375rem] font-semibold tracking-tight text-foreground"
                        >
                            {{ triggerLabel.month }}
                            <span
                                class="font-medium text-muted-foreground tabular-nums"
                                >{{ triggerLabel.year }}</span
                            >
                        </span>
                        <span
                            v-if="rangeText(current)"
                            class="truncate text-[0.6875rem] text-muted-foreground tabular-nums"
                            >{{ rangeText(current) }}</span
                        >
                    </span>
                    <span
                        v-if="currentStatus"
                        class="size-1.5 shrink-0 rounded-full"
                        :class="dotCls(currentStatus)"
                        :title="currentStatus.label"
                        aria-hidden="true"
                    />
                    <ChevronDown
                        class="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-aria-expanded/trigger:rotate-180 motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                </button>
            </template>

            <!-- Contenido -->
            <div class="flex flex-col">
                <div class="flex items-center justify-between px-3 pt-3 pb-2">
                    <button
                        type="button"
                        class="grid size-9 place-items-center rounded-lg text-muted-foreground transition hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none disabled:opacity-30"
                        :disabled="viewYear <= years.min"
                        :aria-label="`Año anterior (${viewYear - 1})`"
                        @click="setYear(viewYear - 1)"
                    >
                        <ChevronLeft class="size-4" />
                    </button>
                    <p
                        class="text-lg font-semibold tracking-tight tabular-nums"
                        aria-live="polite"
                    >
                        {{ viewYear }}
                    </p>
                    <button
                        type="button"
                        class="grid size-9 place-items-center rounded-lg text-muted-foreground transition hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none disabled:opacity-30"
                        :disabled="viewYear >= years.max"
                        :aria-label="`Año siguiente (${viewYear + 1})`"
                        @click="setYear(viewYear + 1)"
                    >
                        <ChevronRight class="size-4" />
                    </button>
                </div>

                <div ref="gridEl" class="relative overflow-hidden px-3 pb-3">
                    <Transition
                        :name="`er-year-${slideDir}`"
                        mode="out-in"
                        @after-enter="afterYearEnter"
                    >
                        <div
                            :key="viewYear"
                            role="grid"
                            :aria-label="`Meses de ${viewYear}`"
                            :data-year="viewYear"
                            class="flex flex-col gap-1.5"
                            @keydown="onGridKey"
                        >
                            <div
                                v-for="(row, r) in rows"
                                :key="r"
                                role="row"
                                class="grid grid-cols-3 gap-1.5"
                            >
                                <div
                                    v-for="c in row"
                                    :key="c.month"
                                    role="gridcell"
                                    :aria-selected="c.selected"
                                >
                                    <button
                                        type="button"
                                        :data-month="c.month"
                                        :tabindex="
                                            c.month === focusMonth ? 0 : -1
                                        "
                                        :aria-disabled="!c.period || undefined"
                                        :aria-label="
                                            c.period
                                                ? `${c.name} ${viewYear}${c.status ? ', ' + c.status.label : ''}${c.latest ? ', el más reciente' : ''}`
                                                : `${c.name} ${viewYear}, sin periodo`
                                        "
                                        class="relative flex h-14 w-full flex-col items-center justify-center rounded-xl text-sm transition focus-visible:ring-3 focus-visible:ring-emerald-500/40 focus-visible:outline-none sm:h-12"
                                        :class="
                                            c.selected
                                                ? 'bg-emerald-700 font-semibold text-white shadow-[0_8px_18px_-10px_rgb(4_120_87/0.9)] dark:bg-emerald-500 dark:text-emerald-950'
                                                : c.period
                                                  ? 'font-medium text-foreground hover:bg-muted'
                                                  : 'cursor-default text-muted-foreground/40'
                                        "
                                        @click="choose(c)"
                                        @focus="focusMonth = c.month"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1"
                                            >{{ c.short
                                            }}<span
                                                v-if="c.status"
                                                class="size-1.5 rounded-full"
                                                :class="[
                                                    dotCls(c.status),
                                                    c.selected &&
                                                        'ring-2 ring-white/60 dark:ring-emerald-950/40',
                                                ]"
                                                aria-hidden="true"
                                        /></span>
                                        <span
                                            v-if="c.latest"
                                            class="text-[0.625rem] leading-none font-medium"
                                            :class="
                                                c.selected
                                                    ? 'text-white/80 dark:text-emerald-950/70'
                                                    : 'text-emerald-700 dark:text-emerald-400'
                                            "
                                            >reciente</span
                                        >
                                    </button>
                                </div>
                            </div>
                        </div>
                    </Transition>
                </div>

                <div
                    class="flex items-end justify-between gap-3 border-t bg-muted/40 px-4 py-3 dark:bg-white/[0.02]"
                >
                    <div class="min-w-0 text-xs leading-snug">
                        <p class="truncate font-semibold text-foreground">
                            {{ current?.label ?? 'Sin periodo' }}
                        </p>
                        <p
                            v-if="rangeText(current, true)"
                            class="text-muted-foreground tabular-nums"
                        >
                            {{ rangeText(current, true) }}
                        </p>
                        <p
                            v-if="legend.length"
                            class="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[0.6875rem] text-muted-foreground"
                        >
                            <span
                                v-for="l in legend"
                                :key="l.cls"
                                class="inline-flex items-center gap-1"
                                ><span
                                    class="size-1.5 rounded-full"
                                    :class="l.cls"
                                />{{ l.label }}</span
                            >
                        </p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg border bg-background px-2.5 text-xs font-medium transition hover:border-emerald-600/40 hover:text-emerald-800 focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none disabled:opacity-40 dark:hover:text-emerald-300"
                        :disabled="!latest || latest.id === periodId"
                        @click="goLatest"
                    >
                        <History class="size-3.5" /> Mes más reciente
                    </button>
                </div>
            </div>
        </ErPickerSurface>

        <Tooltip>
            <TooltipTrigger as-child>
                <button
                    type="button"
                    :class="[arrowCls, 'rounded-r-xl']"
                    :disabled="!newer"
                    :aria-label="
                        newer
                            ? `Periodo siguiente: ${newer.label}`
                            : 'Ya estás en el periodo más reciente'
                    "
                    @click="step(newer)"
                >
                    <ChevronRight class="size-4" />
                </button>
            </TooltipTrigger>
            <TooltipContent v-if="newer">{{ newer.label }}</TooltipContent>
        </Tooltip>
    </div>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .er-year-next-enter-active,
    .er-year-next-leave-active,
    .er-year-prev-enter-active,
    .er-year-prev-leave-active {
        transition:
            opacity 140ms ease,
            transform 160ms cubic-bezier(0.2, 0.8, 0.3, 1);
    }
    .er-year-next-enter-from,
    .er-year-prev-leave-to {
        opacity: 0;
        transform: translateX(16px);
    }
    .er-year-next-leave-to,
    .er-year-prev-enter-from {
        opacity: 0;
        transform: translateX(-16px);
    }
}
</style>
