<script setup lang="ts">
import {
    ArrowUpRight,
    CircleAlert,
    CircleCheck,
    ListTree,
    Lock,
    MousePointerClick,
    Rows3,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { EBITDA_TIER } from './storyline';
import type { StepMeta } from './storyline';
import { isNegative, isZero, mxn, num, pct, sectionOfStep } from './types';
import type { WaterfallStep } from './types';

const props = defineProps<{
    meta: StepMeta | null;
    steps: WaterfallStep[];
    pinned: boolean;
}>();

const emit = defineEmits<{
    openStatement: [sectionKey: string];
    openMovements: [filterKey: string];
}>();

/** Para un resultado: el saldo anterior y los pasos que se le restan (todo del dataset). */
const equation = computed(() => {
    const m = props.meta;

    if (!m || m.step.type === 'delta') {
        return [];
    }

    const i = props.steps.findIndex((s) => s.key === m.step.key);
    const rows: WaterfallStep[] = [];

    for (let j = i - 1; j >= 0; j--) {
        rows.unshift(props.steps[j]);

        if (props.steps[j].type !== 'delta') {
            break;
        }
    }

    return rows;
});

const lines = computed(() => {
    const s = props.meta?.section;

    if (!s || !s.applies) {
        return [];
    }

    const max = Math.max(1, ...s.lines.map((l) => Math.abs(num(l.amount))));

    return s.lines.map((l) => ({
        ...l,
        width: (Math.abs(num(l.amount)) / max) * 100,
    }));
});

const sectionKey = computed(() =>
    props.meta ? sectionOfStep(props.meta.step.key) : '',
);
</script>

<template>
    <aside
        class="flex min-h-full flex-col rounded-2xl border bg-background/60 p-4 dark:bg-white/[0.02]"
        aria-live="polite"
    >
        <div
            v-if="!meta"
            class="flex flex-1 flex-col items-center justify-center gap-2 py-10 text-center text-sm text-muted-foreground"
        >
            <MousePointerClick class="size-7 text-cyan-500" />
            <p class="font-semibold text-foreground">
                Elige un paso de la cascada
            </p>
            <p class="max-w-64 text-xs">
                Verás de dónde sale la cifra, su fórmula y las líneas que la
                forman.
            </p>
        </div>

        <template v-else>
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <p
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <span
                            v-if="EBITDA_TIER[meta.step.key]"
                            class="rounded-full bg-cyan-500/15 px-1.5 py-px text-[10px] font-bold text-cyan-800 dark:text-cyan-300"
                            >{{ EBITDA_TIER[meta.step.key] }}</span
                        >
                        {{
                            meta.step.type === 'delta'
                                ? 'Se resta en este paso'
                                : 'Resultado'
                        }}
                        <span
                            v-if="!pinned"
                            class="text-cyan-700 dark:text-cyan-400"
                            >· vista previa</span
                        >
                    </p>
                    <h3 class="mt-0.5 text-lg leading-tight font-extrabold">
                        {{ meta.step.label }}
                    </h3>
                </div>
                <span
                    class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold"
                    :class="
                        meta.complete
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                            : 'bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-300'
                    "
                >
                    <CircleCheck v-if="meta.complete" class="size-3.5" />
                    <CircleAlert v-else class="size-3.5" />
                    {{ meta.complete ? 'Completo' : 'Preliminar' }}
                </span>
            </div>

            <p
                class="mt-2 text-3xl font-extrabold tracking-tight tabular-nums"
                :class="
                    isNegative(meta.step.amount)
                        ? 'text-rose-600 dark:text-rose-400'
                        : ''
                "
            >
                {{ mxn(meta.step.amount) }}
            </p>
            <p
                v-if="meta.result?.pct || meta.section?.total.pct"
                class="text-xs text-muted-foreground"
            >
                {{ pct(meta.result?.pct ?? meta.section?.total.pct) }} de los
                ingresos operativos
            </p>

            <!-- Fórmula -->
            <div class="mt-4 rounded-xl bg-muted/50 p-3 text-xs">
                <p class="font-semibold">Cómo se obtiene</p>
                <p class="mt-0.5 text-muted-foreground">{{ meta.formula }}</p>
                <ul v-if="equation.length" class="mt-2 space-y-1 tabular-nums">
                    <li
                        v-for="s in equation"
                        :key="s.key"
                        class="flex justify-between gap-2"
                    >
                        <span class="truncate">{{
                            s.type === 'delta' ? `− ${s.label}` : s.label
                        }}</span>
                        <span
                            :class="
                                isNegative(s.amount)
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : ''
                            "
                            >{{ mxn(s.amount) }}</span
                        >
                    </li>
                    <li
                        class="flex justify-between gap-2 border-t pt-1 font-bold"
                    >
                        <span>= {{ meta.step.label }}</span>
                        <span>{{ mxn(meta.step.amount) }}</span>
                    </li>
                </ul>
                <p
                    v-if="meta.sources.length"
                    class="mt-2 text-muted-foreground"
                >
                    <span class="font-semibold text-foreground">Fuente:</span>
                    {{ meta.sources.join(' · ') }}
                </p>
            </div>

            <!-- Qué falta -->
            <div
                v-if="!meta.complete && meta.missing.length"
                class="mt-3 rounded-xl border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <p class="font-semibold">Falta información (no se asume $0)</p>
                <p class="mt-0.5">{{ meta.missing.join(', ') }}</p>
            </div>

            <!-- Líneas de la sección -->
            <div v-if="meta.section" class="mt-4 min-h-0 flex-1">
                <p class="mb-2 text-xs font-semibold">
                    Líneas que lo forman
                    <span class="font-normal text-muted-foreground"
                        >· {{ meta.section.lines.length }}</span
                    >
                </p>
                <p
                    v-if="!meta.section.applies"
                    class="text-xs text-muted-foreground italic"
                >
                    {{ meta.section.note }}
                </p>
                <p
                    v-else-if="!lines.length"
                    class="text-xs text-muted-foreground"
                >
                    Sin líneas con importe en este periodo.
                </p>
                <ul v-else class="max-h-64 space-y-1.5 overflow-y-auto pr-1">
                    <li v-for="l in lines" :key="l.key" class="text-xs">
                        <span class="flex items-center justify-between gap-2">
                            <span
                                class="flex min-w-0 items-center gap-1 truncate"
                                :class="
                                    l.state === 'sin_informacion'
                                        ? 'text-muted-foreground italic'
                                        : ''
                                "
                                :title="l.hint ?? l.label"
                            >
                                <Lock
                                    v-if="
                                        l.redacted || l.state === 'restringido'
                                    "
                                    class="size-3 shrink-0"
                                />
                                {{ l.label }}
                            </span>
                            <span
                                class="shrink-0 tabular-nums"
                                :class="
                                    isZero(l.amount)
                                        ? 'text-muted-foreground'
                                        : 'font-semibold'
                                "
                                >{{
                                    l.state === 'sin_informacion'
                                        ? 'Sin información'
                                        : mxn(l.amount)
                                }}</span
                            >
                        </span>
                        <span
                            class="mt-0.5 block h-1 overflow-hidden rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full bg-emerald-500/80 transition-[width] duration-500"
                                :style="{ width: `${l.width}%` }"
                            />
                        </span>
                    </li>
                </ul>
            </div>

            <div class="mt-4 flex flex-wrap gap-2 border-t pt-3">
                <button
                    v-if="meta.section || meta.result"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-xl border bg-card px-3 py-2 text-xs font-semibold transition hover:border-emerald-400 hover:text-emerald-800 focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none dark:hover:text-emerald-300"
                    @click="emit('openStatement', sectionKey)"
                >
                    <ListTree class="size-3.5" /> Ver en el estado de resultados
                    <ArrowUpRight class="size-3.5" />
                </button>
                <button
                    v-if="meta.movementCount"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-xl border bg-card px-3 py-2 text-xs font-semibold transition hover:border-cyan-400 hover:text-cyan-800 focus-visible:ring-3 focus-visible:ring-cyan-500/30 focus-visible:outline-none dark:hover:text-cyan-300"
                    @click="emit('openMovements', `line:${sectionKey}`)"
                >
                    <Rows3 class="size-3.5" />
                    {{ meta.movementCount }} movimiento(s) del banco suman aquí
                    <ArrowUpRight class="size-3.5" />
                </button>
            </div>
        </template>
    </aside>
</template>
