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
import { EBITDA_TIER } from './storyline';
import { isNegative, isZero, mxn, pct } from './types';
import type { Movement, StatementBlock } from './types';

const props = defineProps<{
    blocks: StatementBlock[];
    movements: Movement[];
    /** Sección o resultado a resaltar (viene de la cascada). */
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

const sectionKeys = computed(() =>
    props.blocks.filter((b) => b.type === 'section').map((b) => b.key),
);
const allCollapsed = computed(() =>
    sectionKeys.value.every((k) => collapsed[k]),
);

function toggleAll(): void {
    const v = !allCollapsed.value;
    sectionKeys.value.forEach((k) => (collapsed[k] = v));
}

let flashTimer: ReturnType<typeof setTimeout> | undefined;

async function reveal(key: string | null): Promise<void> {
    if (!key) {
        return;
    }

    collapsed[key] = false;
    await nextTick();
    const el = root.value?.querySelector<HTMLElement>(
        `[data-er-block="${key}"]`,
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

const RESULT_TONE: Record<number, string> = {
    1: 'bg-emerald-800 text-white dark:bg-emerald-900/80',
    2: 'bg-emerald-800 text-white dark:bg-emerald-900/80',
    3: 'bg-emerald-900 text-white dark:bg-emerald-950',
    4: 'bg-slate-800 text-white dark:bg-slate-800/80',
    5: 'bg-slate-800 text-white dark:bg-slate-800/80',
    6: 'bg-slate-950 text-white dark:bg-black',
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
            <!-- Sección -->
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
                            >{{ b.number }}</span
                        >
                        <span class="truncate">{{ b.title }}</span>
                        <span
                            v-if="b.restricted"
                            class="inline-flex shrink-0 items-center gap-1 rounded-full border border-slate-300 bg-slate-100 px-2 py-px text-[10px] font-bold text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300"
                            title="Tu rol ve sólo el total de esta sección; el desglose requiere permiso."
                            ><Lock class="size-3" /> Detalle restringido</span
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
                        <Rows3 class="size-3" />
                        {{ movementCount[b.key] }} mov.
                    </button>
                </div>

                <p
                    v-if="!b.applies"
                    class="px-4 pb-3 pl-12 text-xs text-muted-foreground italic"
                >
                    {{ b.note }}
                </p>
                <template v-else>
                    <div v-show="!collapsed[b.key]">
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
                                    v-if="
                                        l.redacted || l.state === 'restringido'
                                    "
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                                <span
                                    class="truncate"
                                    :class="
                                        l.state === 'sin_informacion' ||
                                        l.redacted ||
                                        l.state === 'restringido'
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
                                        <p
                                            v-if="
                                                l.redacted ||
                                                l.state === 'restringido'
                                            "
                                        >
                                            {{
                                                l.redacted_reason ??
                                                'Tu rol no puede ver este detalle; el total sí se incluye.'
                                            }}
                                        </p>
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
                    </div>
                    <div
                        class="grid grid-cols-[minmax(0,1fr)_9.5rem] items-center gap-x-4 border-t border-dashed px-4 py-2 text-sm font-bold sm:grid-cols-[minmax(0,1fr)_10rem_5.5rem]"
                    >
                        <span class="truncate pl-8">{{ b.total.label }}</span>
                        <span class="text-right tabular-nums">{{
                            mxn(b.total.amount)
                        }}</span>
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
                </template>
            </div>

            <!-- Resultado (EBITDA / EBIT / EBT / Utilidad) -->
            <div
                v-else
                :data-er-block="b.key"
                class="grid grid-cols-[minmax(0,1fr)_9.5rem] items-center gap-x-4 px-4 py-3.5 transition-shadow duration-700 sm:grid-cols-[minmax(0,1fr)_10rem_5.5rem]"
                :class="[
                    RESULT_TONE[b.level] ?? RESULT_TONE[4],
                    flash === b.key
                        ? 'shadow-[inset_0_0_0_3px_rgb(34_197_214)]'
                        : '',
                ]"
            >
                <div class="min-w-0">
                    <p
                        class="flex flex-wrap items-center gap-2 text-sm font-extrabold"
                    >
                        <span
                            v-if="EBITDA_TIER[b.key]"
                            class="rounded-full bg-cyan-400/25 px-2 py-px text-[10px] font-bold text-cyan-100"
                            >{{ EBITDA_TIER[b.key] }}</span
                        >
                        = {{ b.label }}
                        <Tooltip v-if="!b.complete">
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
                </div>
                <span
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
