<script setup lang="ts">
import {
    ChevronDown,
    ChevronsDownUp,
    ChevronsUpDown,
    Info,
    Lock,
    Rows3,
} from 'lucide-vue-next';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { isNegative, isZero, mxn, pct } from './types';
import type { Movement, StatementBlock, StatementLine } from './types';

const props = defineProps<{
    blocks: StatementBlock[];
    movements: Movement[];
    /** Bloque a resaltar: 'ebitda', 'da', u 'opex:<categoría>'. */
    highlight: string | null;
}>();

const emit = defineEmits<{ openMovements: [filterKey: string] }>();

const collapsed = reactive<Record<string, boolean>>({});
const root = ref<HTMLElement | null>(null);
const flash = ref<string | null>(null);

const movementCount = computed(() => {
    const map: Record<string, number> = {};

    for (const m of props.movements) {
        if (m.impact.included && m.impact.line) {
            map[m.impact.line] = (map[m.impact.line] ?? 0) + 1;
        }
    }

    return map;
});

/** Claves colapsables: secciones y categorías de OPEX. */
const collapsible = computed(() =>
    props.blocks.flatMap((b) =>
        b.type === 'section'
            ? [b.key, ...(b.categories ?? []).map((c) => `opex:${c.key}`)]
            : [],
    ),
);
const allCollapsed = computed(() =>
    collapsible.value.every((k) => collapsed[k]),
);

function toggleAll(): void {
    const v = !allCollapsed.value;
    collapsible.value.forEach((k) => (collapsed[k] = v));
}

let flashTimer: ReturnType<typeof setTimeout> | undefined;

async function reveal(key: string | null): Promise<void> {
    if (!key) {
        return;
    }

    collapsed[key] = false;

    if (key.startsWith('opex:')) {
        collapsed.opex = false;
    }

    await nextTick();
    const el = root.value?.querySelector<HTMLElement>(
        `[data-er-block="${CSS.escape(key)}"]`,
    );

    if (!el) {
        return;
    }

    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    flash.value = key;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => (flash.value = null), 1800);
}

watch(() => props.highlight, reveal, { immediate: true });

const restrictedLine = (l: StatementLine) =>
    l.redacted || l.state === 'restringido';
const RESULT_TONE: Record<string, string> = {
    ebitda: 'bg-cyan-800 text-white dark:bg-cyan-950',
    utilidad_neta: 'bg-emerald-900 text-white dark:bg-emerald-950',
};
</script>

<template>
    <div
        ref="root"
        class="overflow-clip rounded-2xl border bg-card shadow-soft-xs"
    >
        <div
            class="sticky top-[3.75rem] z-10 grid grid-cols-[minmax(0,1fr)_9.5rem] items-center gap-x-4 border-b bg-card/95 px-4 py-2.5 text-xs font-semibold text-muted-foreground backdrop-blur sm:grid-cols-[minmax(0,1fr)_10rem_5.5rem]"
        >
            <span class="flex items-center gap-2">
                Concepto
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-semibold transition hover:bg-muted hover:text-foreground"
                    @click="toggleAll"
                >
                    <component
                        :is="allCollapsed ? ChevronsUpDown : ChevronsDownUp"
                        class="size-3.5"
                    />
                    {{ allCollapsed ? 'Expandir todo' : 'Contraer todo' }}
                </button>
            </span>
            <span class="text-right">Importe MXN</span>
            <span class="hidden text-right sm:block">% ingresos</span>
        </div>

        <template v-for="b in blocks" :key="b.key">
            <!-- Sección (con categorías si es OPEX) -->
            <div
                v-if="b.type === 'section'"
                :data-er-block="b.key"
                class="border-b transition-colors duration-700 last:border-b-0"
                :class="flash === b.key ? 'bg-cyan-50 dark:bg-cyan-950/30' : ''"
            >
                <div class="flex items-center gap-2 px-4 py-2.5">
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 items-center gap-2.5 text-left text-sm font-bold transition hover:text-emerald-800 dark:hover:text-emerald-300"
                        :aria-expanded="!collapsed[b.key]"
                        @click="collapsed[b.key] = !collapsed[b.key]"
                    >
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-md bg-muted text-[11px] font-extrabold text-muted-foreground tabular-nums"
                            >{{ b.step ?? b.number }}</span
                        >
                        <span class="truncate">{{ b.title }}</span>
                        <span
                            v-if="b.restricted"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full border border-slate-300 bg-slate-100 px-2 py-px text-[10px] font-bold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                            ><Lock class="size-3" /> Detalle restringido</span
                        >
                        <span
                            v-if="b.state === 'pendiente'"
                            class="shrink-0 rounded-full border border-amber-300 bg-amber-50 px-2 py-px text-[10px] font-bold text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
                            >Pendiente</span
                        >
                        <ChevronDown
                            class="size-4 shrink-0 text-muted-foreground transition-transform"
                            :class="collapsed[b.key] ? '-rotate-90' : ''"
                        />
                    </button>
                    <button
                        v-if="movementCount[b.key]"
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold text-cyan-800 transition hover:border-cyan-400 hover:bg-cyan-50 dark:text-cyan-300 dark:hover:bg-cyan-950/40"
                        :title="`Ver los ${movementCount[b.key]} movimientos de banco que suman a esta sección`"
                        @click="emit('openMovements', `line:${b.key}`)"
                    >
                        <Rows3 class="size-3" /> {{ movementCount[b.key] }} mov.
                    </button>
                </div>

                <p
                    v-if="b.note"
                    class="px-4 pb-2 pl-12 text-xs"
                    :class="
                        b.state === 'pendiente'
                            ? 'font-semibold text-amber-800 dark:text-amber-300'
                            : 'text-muted-foreground italic'
                    "
                >
                    {{ b.note }}
                </p>

                <div v-show="!collapsed[b.key]">
                    <!-- OPEX: una sub-sección por categoría -->
                    <template v-if="b.categories?.length">
                        <div
                            v-for="c in b.categories"
                            :key="c.key"
                            :data-er-block="`opex:${c.key}`"
                            class="transition-colors duration-700"
                            :class="
                                flash === `opex:${c.key}`
                                    ? 'bg-cyan-50 dark:bg-cyan-950/30'
                                    : ''
                            "
                        >
                            <div
                                class="grid grid-cols-[minmax(0,1fr)_9.5rem] items-center gap-x-4 px-4 py-1.5 text-sm sm:grid-cols-[minmax(0,1fr)_10rem_5.5rem]"
                            >
                                <span
                                    class="flex min-w-0 items-center gap-1.5 pl-8"
                                >
                                    <button
                                        type="button"
                                        class="flex min-w-0 items-center gap-1.5 font-semibold transition hover:text-emerald-800 dark:hover:text-emerald-300"
                                        :aria-expanded="
                                            !collapsed[`opex:${c.key}`]
                                        "
                                        @click="
                                            collapsed[`opex:${c.key}`] =
                                                !collapsed[`opex:${c.key}`]
                                        "
                                    >
                                        <ChevronDown
                                            class="size-3.5 shrink-0 text-muted-foreground transition-transform"
                                            :class="
                                                collapsed[`opex:${c.key}`]
                                                    ? '-rotate-90'
                                                    : ''
                                            "
                                        />
                                        <span class="truncate">{{
                                            c.label
                                        }}</span>
                                    </button>
                                    <Lock
                                        v-if="c.restricted"
                                        class="size-3.5 shrink-0 text-muted-foreground"
                                    />
                                    <button
                                        v-if="movementCount[c.key]"
                                        type="button"
                                        class="shrink-0 rounded-full border px-1.5 text-[10px] font-semibold text-cyan-800 hover:border-cyan-400 dark:text-cyan-300"
                                        @click="
                                            emit(
                                                'openMovements',
                                                `line:${c.key}`,
                                            )
                                        "
                                    >
                                        {{ movementCount[c.key] }} mov.
                                    </button>
                                </span>
                                <span
                                    class="text-right font-semibold tabular-nums"
                                    >{{
                                        c.total.amount === null
                                            ? 'Pendiente'
                                            : mxn(c.total.amount)
                                    }}</span
                                >
                                <span
                                    class="hidden text-right text-xs text-muted-foreground tabular-nums sm:block"
                                    >{{ pct(c.total.pct) }}</span
                                >
                            </div>
                            <div v-show="!collapsed[`opex:${c.key}`]">
                                <p
                                    v-if="!c.lines.length"
                                    class="px-4 pb-1.5 pl-16 text-xs text-muted-foreground"
                                >
                                    Sin líneas en este periodo.
                                </p>
                                <div
                                    v-for="l in c.lines"
                                    :key="l.key"
                                    class="group grid grid-cols-[minmax(0,1fr)_9.5rem] items-center gap-x-4 px-4 py-1 text-[13px] transition-colors hover:bg-muted/50 sm:grid-cols-[minmax(0,1fr)_10rem_5.5rem]"
                                >
                                    <span
                                        class="flex min-w-0 items-center gap-1.5 pl-16"
                                    >
                                        <Lock
                                            v-if="restrictedLine(l)"
                                            class="size-3 shrink-0 text-muted-foreground"
                                        />
                                        <span
                                            class="truncate"
                                            :class="
                                                restrictedLine(l) ||
                                                l.state === 'sin_informacion'
                                                    ? 'text-muted-foreground italic'
                                                    : ''
                                            "
                                            :title="l.hint ?? l.label"
                                            >{{ l.label }}</span
                                        >
                                    </span>
                                    <span
                                        class="text-right tabular-nums"
                                        :class="
                                            isZero(l.amount)
                                                ? 'text-muted-foreground'
                                                : ''
                                        "
                                        >{{ mxn(l.amount) }}</span
                                    >
                                    <span
                                        class="hidden text-right text-xs text-muted-foreground tabular-nums sm:block"
                                        >{{ pct(l.pct) }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Resto de secciones: líneas directas -->
                    <template v-else>
                        <p
                            v-if="!b.lines.length"
                            class="px-4 pb-2 pl-12 text-xs text-muted-foreground"
                        >
                            Sin líneas en este periodo.
                        </p>
                        <div
                            v-for="l in b.lines"
                            :key="l.key"
                            class="group grid grid-cols-[minmax(0,1fr)_9.5rem] items-center gap-x-4 px-4 py-1.5 text-sm transition-colors hover:bg-muted/50 sm:grid-cols-[minmax(0,1fr)_10rem_5.5rem]"
                        >
                            <span
                                class="flex min-w-0 items-center gap-1.5 pl-8"
                            >
                                <Lock
                                    v-if="restrictedLine(l)"
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                                <span
                                    class="truncate"
                                    :class="
                                        l.state === 'sin_informacion' ||
                                        restrictedLine(l)
                                            ? 'text-muted-foreground italic'
                                            : ''
                                    "
                                    >{{ l.label }}</span
                                >
                                <span
                                    v-if="l.state === 'sin_informacion'"
                                    class="shrink-0 rounded-full border border-amber-300 bg-amber-50 px-1.5 text-[10px] font-bold text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
                                    >Sin información</span
                                >
                                <Tooltip v-if="l.hint || l.source">
                                    <TooltipTrigger as-child>
                                        <button
                                            type="button"
                                            class="shrink-0 rounded text-muted-foreground/60 opacity-60 transition group-hover:opacity-100 hover:text-foreground focus-visible:opacity-100"
                                            :aria-label="`De dónde sale: ${l.label}`"
                                        >
                                            <Info class="size-3.5" />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent class="max-w-xs">
                                        <p class="font-semibold">
                                            Fuente: {{ l.source }}
                                        </p>
                                        <p v-if="l.hint">{{ l.hint }}</p>
                                    </TooltipContent>
                                </Tooltip>
                            </span>
                            <span
                                class="text-right tabular-nums"
                                :class="
                                    l.state === 'sin_informacion' ||
                                    isZero(l.amount)
                                        ? 'text-muted-foreground'
                                        : isNegative(l.amount)
                                          ? 'text-rose-600 dark:text-rose-400'
                                          : ''
                                "
                                >{{
                                    l.state === 'sin_informacion'
                                        ? '—'
                                        : mxn(l.amount)
                                }}</span
                            >
                            <span
                                class="hidden text-right text-xs text-muted-foreground tabular-nums sm:block"
                                >{{ pct(l.pct) }}</span
                            >
                        </div>
                    </template>
                </div>

                <div
                    class="grid grid-cols-[minmax(0,1fr)_9.5rem] items-center gap-x-4 border-t border-dashed px-4 py-2 text-sm font-bold sm:grid-cols-[minmax(0,1fr)_10rem_5.5rem]"
                >
                    <span class="truncate pl-8">{{ b.total.label }}</span>
                    <span
                        class="text-right tabular-nums"
                        :class="
                            b.total.amount === null
                                ? 'text-amber-700 dark:text-amber-300'
                                : isNegative(b.total.amount)
                                  ? 'text-rose-600 dark:text-rose-400'
                                  : ''
                        "
                        >{{
                            b.total.amount === null
                                ? 'Pendiente'
                                : mxn(b.total.amount)
                        }}</span
                    >
                    <span
                        class="hidden text-right text-xs tabular-nums sm:block"
                        >{{ pct(b.total.pct) }}</span
                    >
                </div>
                <p
                    v-if="b.subtotals"
                    class="flex flex-wrap gap-x-4 px-4 pb-2 pl-12 text-xs text-muted-foreground"
                >
                    <span v-for="s in b.subtotals" :key="s.label"
                        >{{ s.label }}:
                        <b class="text-foreground tabular-nums">{{
                            mxn(s.amount)
                        }}</b></span
                    >
                </p>
            </div>

            <!-- Resultado: EBITDA (único), EBIT, EBT, Utilidad neta -->
            <div
                v-else
                :data-er-block="b.key"
                class="grid grid-cols-[minmax(0,1fr)_9.5rem] items-center gap-x-4 px-4 py-3.5 transition-shadow duration-700 sm:grid-cols-[minmax(0,1fr)_10rem_5.5rem]"
                :class="[
                    RESULT_TONE[b.key] ??
                        'bg-slate-800 text-white dark:bg-slate-800/80',
                    flash === b.key
                        ? 'shadow-[inset_0_0_0_3px_rgb(34_197_214)]'
                        : '',
                ]"
            >
                <div class="min-w-0">
                    <p
                        class="flex flex-wrap items-center gap-2 text-sm font-extrabold"
                    >
                        = {{ b.label }}
                        <Tooltip v-if="!b.complete && b.amount !== null">
                            <TooltipTrigger as-child>
                                <span
                                    class="cursor-help rounded-full bg-amber-300 px-1.5 text-[10px] font-bold text-amber-950"
                                    >Preliminar</span
                                >
                            </TooltipTrigger>
                            <TooltipContent class="max-w-xs">
                                No se asume $0. Falta:
                                {{ b.missing.join(', ') }}.
                            </TooltipContent>
                        </Tooltip>
                    </p>
                    <p class="truncate text-[11px] opacity-75">
                        {{ b.formula }}
                    </p>
                    <p
                        v-if="b.note"
                        class="text-[11px] font-semibold text-amber-200"
                    >
                        {{ b.note }}
                    </p>
                </div>
                <span
                    v-if="b.amount === null"
                    class="justify-self-end rounded-lg border-2 border-dashed border-amber-300 px-2 py-0.5 text-sm font-extrabold text-amber-200"
                    >Pendiente</span
                >
                <span
                    v-else
                    class="text-right text-base font-extrabold tabular-nums"
                    :class="
                        isNegative(b.amount)
                            ? 'text-rose-300'
                            : 'text-emerald-200'
                    "
                    >{{ mxn(b.amount) }}</span
                >
                <span
                    class="hidden text-right text-xs font-bold tabular-nums opacity-80 sm:block"
                    >{{ pct(b.pct) }}</span
                >
            </div>
        </template>
    </div>
</template>
