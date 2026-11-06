<script setup lang="ts">
import { computed } from 'vue';
import { STEP_GROUPS, groupOf } from './storyline';
import type { StepMeta } from './storyline';
import { compact, isZero, mxn, num } from './types';
import type { WaterfallStep } from './types';

const props = defineProps<{
    steps: WaterfallStep[];
    meta: Record<string, StepMeta>;
    /** Paso fijado (clic) y paso en vista previa (hover/foco). */
    selected: string | null;
    preview: string | null;
}>();

const emit = defineEmits<{
    select: [key: string];
    preview: [key: string | null];
}>();

/** Etiquetas cortas del eje (la completa va en el tooltip y en aria-label). */
const SHORT_LABEL: Record<string, string> = {
    ingresos_operativos: 'Ingresos',
    opex_sucursales: 'Gastos suc.',
    nomina_sucursales: 'Nómina suc.',
    opex_corporativo: 'Corporativo',
    nomina_corporativa: 'Nómina corp.',
    dg: 'Dirección General',
    ebitda: 'EBITDA',
    depreciacion_amortizacion: 'D y A',
    ebit: 'EBIT',
    resultado_financiero_neto: 'Financiero',
    ebt: 'EBT',
    isr: 'ISR',
    utilidad_neta: 'Utilidad neta',
};

const focusKey = computed(() => props.preview ?? props.selected);

/**
 * Barras flotantes: cada delta parte del acumulado; totales parten de 0.
 * Un paso pendiente (null) no dibuja barra y deja pendiente lo que sigue: nunca $0.
 * Sólo geometría: los importes mostrados son los del dataset.
 */
const bars = computed(() => {
    let running: number | null = 0;
    const raw = props.steps.map((s) => {
        const pending = s.amount === null || s.pending === true;
        const value = pending ? 0 : num(s.amount);
        let from: number | null = 0;
        let to: number | null = value;

        if (pending) {
            from = s.type === 'delta' ? running : null;
            to = null;
        } else if (s.type === 'delta') {
            from = running;
            to = running === null ? null : running + value;
        }

        running = to;

        return { ...s, from, to, value, pending };
    });
    const values = raw.flatMap((b) => [b.from ?? 0, b.to ?? 0, 0]);
    const rawMin = Math.min(...values);
    const rawMax = Math.max(...values);
    const range = rawMax - rawMin || 1;
    const min = rawMin < 0 ? rawMin - range * 0.06 : 0;
    const max = rawMax > 0 ? rawMax + range * 0.06 : 0;
    const span = max - min || 1;
    const y = (v: number) => ((v - min) / span) * 100;

    return raw.map((b, i) => {
        const drawable = !b.pending && b.from !== null && b.to !== null;
        const lo = drawable ? Math.min(b.from as number, b.to as number) : 0;
        const hi = drawable ? Math.max(b.from as number, b.to as number) : 0;

        return {
            ...b,
            index: i,
            drawable,
            bottom: y(lo),
            height: drawable ? Math.max(y(hi) - y(lo), 0.6) : 0,
            fromY: b.from === null ? null : y(b.from),
            toY: b.to === null ? null : y(b.to),
            zero: y(0),
            group: groupOf(b),
        };
    });
});

const groups = computed(() => {
    const out: Array<{ id: number; label: string; count: number }> = [];

    for (const b of bars.value) {
        const last = out[out.length - 1];

        if (last && last.id === b.group) {
            last.count++;
        } else {
            out.push({
                id: b.group,
                label: STEP_GROUPS[b.group].label,
                count: 1,
            });
        }
    }

    return out;
});

const maxAbs = computed(() =>
    Math.max(1, ...bars.value.map((b) => Math.abs(b.value))),
);

function barTone(b: { type: string; value: number; key: string }): string {
    const neg = b.value < 0;

    if (b.type !== 'delta') {
        return neg
            ? 'bg-rose-600 dark:bg-rose-500'
            : b.key === 'ebitda'
              ? 'bg-cyan-600 dark:bg-cyan-500'
              : 'bg-emerald-700 dark:bg-emerald-500';
    }

    return neg
        ? 'bg-[repeating-linear-gradient(135deg,rgb(244_63_94/0.85)_0_5px,rgb(244_63_94/0.55)_5px_10px)]'
        : 'bg-[repeating-linear-gradient(135deg,rgb(16_185_129/0.85)_0_5px,rgb(16_185_129/0.55)_5px_10px)]';
}

/** Etiqueta del importe: fuera de la barra, o dentro si chocaría con el borde o el eje. */
function labelPos(b: { value: number; bottom: number; height: number }) {
    const top = b.bottom + b.height;

    if (b.value >= 0) {
        return top > 88
            ? {
                  inside: b.height > 8,
                  style: { bottom: `calc(${top}% - 16px)` },
              }
            : { inside: false, style: { bottom: `calc(${top}% + 4px)` } };
    }

    return b.bottom < 10
        ? {
              inside: b.height > 8,
              style: { bottom: `calc(${b.bottom}% + 4px)` },
          }
        : { inside: false, style: { bottom: `calc(${b.bottom}% - 16px)` } };
}

function onKey(e: KeyboardEvent, i: number): void {
    const dir = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;

    if (!dir) {
        return;
    }

    e.preventDefault();
    const next = bars.value[i + dir];

    if (next) {
        emit('select', next.key);
        document
            .querySelector<HTMLElement>(`[data-er-step="${next.key}"]`)
            ?.focus();
    }
}
</script>

<template>
    <div>
        <!-- Escritorio -->
        <div
            class="hidden md:block"
            role="group"
            aria-label="Cascada de resultados"
        >
            <div class="flex">
                <div
                    v-for="g in groups"
                    :key="g.id + g.label"
                    class="px-1.5"
                    :style="{ flex: g.count }"
                >
                    <p
                        class="truncate text-xs font-bold text-muted-foreground"
                        :title="g.label"
                    >
                        {{ g.label }}
                    </p>
                    <span
                        class="mt-1 block h-1 rounded-full"
                        :class="
                            g.id === 2
                                ? 'bg-rose-400/70 dark:bg-rose-500/60'
                                : g.id === 1
                                  ? 'bg-emerald-600/70 dark:bg-emerald-500/60'
                                  : 'bg-cyan-500/70 dark:bg-cyan-500/60'
                        "
                    />
                </div>
            </div>

            <div class="relative mt-3 flex" @mouseleave="emit('preview', null)">
                <button
                    v-for="b in bars"
                    :key="b.key"
                    type="button"
                    :data-er-step="b.key"
                    class="group/bar relative flex min-w-0 flex-1 flex-col rounded-xl text-left transition-[background-color,opacity] duration-200 focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none"
                    :class="[
                        focusKey === b.key
                            ? 'bg-emerald-50 dark:bg-emerald-950/30'
                            : 'hover:bg-muted/50',
                        focusKey && focusKey !== b.key ? 'opacity-55' : '',
                    ]"
                    :aria-pressed="selected === b.key"
                    :aria-label="`${b.label}: ${b.pending ? 'pendiente' : mxn(b.amount)}. ${meta[b.key]?.formula ?? ''}`"
                    @mouseenter="emit('preview', b.key)"
                    @focus="emit('preview', b.key)"
                    @blur="emit('preview', null)"
                    @click="emit('select', b.key)"
                    @keydown="onKey($event, b.index)"
                >
                    <span class="relative mx-0.5 block h-64 lg:h-72 2xl:h-80">
                        <span
                            class="absolute inset-x-0 border-t border-slate-300 dark:border-slate-700"
                            :style="{ bottom: `${b.zero}%` }"
                        />
                        <span
                            v-if="
                                b.index > 0 &&
                                (b.type === 'delta' ? b.fromY : b.toY) !== null
                            "
                            class="absolute left-0 w-[22%] border-t border-dashed border-slate-400/80 dark:border-slate-500"
                            :style="{
                                bottom: `${b.type === 'delta' ? b.fromY : b.toY}%`,
                            }"
                        />
                        <span
                            v-if="b.index < bars.length - 1 && b.toY !== null"
                            class="absolute right-0 w-[22%] border-t border-dashed border-slate-400/80 dark:border-slate-500"
                            :style="{ bottom: `${b.toY}%` }"
                        />
                        <span
                            v-if="b.drawable"
                            class="er-grow absolute inset-x-[22%] rounded-[6px] shadow-sm transition-[filter] duration-200 group-hover/bar:brightness-110"
                            :class="[
                                barTone(b),
                                b.type !== 'delta'
                                    ? 'ring-1 ring-black/5 dark:ring-white/10'
                                    : '',
                                b.value < 0 ? 'er-grow-down' : '',
                            ]"
                            :style="{
                                bottom: `${b.bottom}%`,
                                height: `${b.height}%`,
                                animationDelay: `${b.index * 55}ms`,
                            }"
                        />
                        <!-- pendiente: hueco punteado, nunca una barra en $0 -->
                        <span
                            v-else
                            class="absolute inset-x-[18%] flex h-16 items-center justify-center rounded-lg border-2 border-dashed border-amber-400 bg-amber-50/60 text-[10px] font-bold text-amber-800 dark:bg-amber-950/30 dark:text-amber-300"
                            :style="{
                                bottom: `${Math.max(2, Math.min(b.zero - 24, 74))}%`,
                            }"
                            >Pendiente</span
                        >
                        <span
                            v-if="b.drawable"
                            class="absolute inset-x-0 text-center text-[10.5px] font-bold tracking-tight whitespace-nowrap tabular-nums"
                            :class="
                                labelPos(b).inside
                                    ? 'text-white drop-shadow-[0_1px_1px_rgb(0_0_0/0.4)]'
                                    : isZero(b.amount)
                                      ? 'text-muted-foreground'
                                      : b.value < 0
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : 'text-emerald-800 dark:text-emerald-300'
                            "
                            :style="labelPos(b).style"
                            >{{ compact(b.amount) }}</span
                        >
                        <!-- tooltip: fórmula + fuente + importe exacto -->
                        <span
                            class="pointer-events-none absolute bottom-[calc(100%+6px)] z-20 w-64 translate-y-1 rounded-xl border bg-popover p-3 text-xs text-popover-foreground opacity-0 shadow-soft-lg transition duration-150 group-hover/bar:translate-y-0 group-hover/bar:opacity-100"
                            :class="
                                b.index === 0
                                    ? 'left-0'
                                    : b.index >= bars.length - 2
                                      ? 'right-0'
                                      : 'left-1/2 -translate-x-1/2'
                            "
                            role="presentation"
                        >
                            <span class="block font-bold">{{ b.label }}</span>
                            <span
                                class="mt-0.5 block text-base font-extrabold tabular-nums"
                                :class="
                                    b.pending
                                        ? 'text-amber-700 dark:text-amber-300'
                                        : b.value < 0
                                          ? 'text-rose-600 dark:text-rose-400'
                                          : ''
                                "
                                >{{
                                    b.pending ? 'Pendiente' : mxn(b.amount)
                                }}</span
                            >
                            <span
                                v-if="meta[b.key]?.formula"
                                class="mt-1.5 block text-muted-foreground"
                                >{{ meta[b.key].formula }}</span
                            >
                            <span
                                v-if="meta[b.key]?.sources.length"
                                class="mt-1 block text-muted-foreground"
                                >Fuente:
                                {{ meta[b.key].sources.join(' · ') }}</span
                            >
                            <span
                                class="mt-1.5 block font-semibold text-cyan-700 dark:text-cyan-300"
                                >Clic para ver el detalle</span
                            >
                        </span>
                    </span>
                    <span
                        class="block min-h-10 px-0.5 pt-2 pb-2 text-center text-[11px] leading-tight"
                        :class="
                            b.type === 'delta'
                                ? 'text-muted-foreground'
                                : 'font-bold text-foreground'
                        "
                        :title="b.label"
                        >{{ SHORT_LABEL[b.key] ?? b.label }}</span
                    >
                </button>
            </div>
            <div
                class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-muted-foreground"
            >
                <span class="inline-flex items-center gap-1.5"
                    ><span
                        class="size-2.5 rounded-sm bg-emerald-700 dark:bg-emerald-500"
                    />
                    Resultado</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><span
                        class="size-2.5 rounded-sm bg-cyan-600 dark:bg-cyan-500"
                    />
                    EBITDA</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><span
                        class="size-2.5 rounded-sm bg-[repeating-linear-gradient(135deg,rgb(244_63_94/0.85)_0_2px,rgb(244_63_94/0.5)_2px_4px)]"
                    />
                    Lo que se resta o suma en ese paso</span
                >
                <span class="inline-flex items-center gap-1.5"
                    ><span
                        class="size-2.5 rounded-sm border-2 border-dashed border-amber-400"
                    />
                    Pendiente (sin información)</span
                >
                <span>Usa ← → para recorrer los pasos.</span>
            </div>
        </div>

        <!-- Móvil -->
        <ol class="space-y-1 md:hidden">
            <template v-for="b in bars" :key="b.key">
                <li
                    v-if="b.index === 0 || bars[b.index - 1].group !== b.group"
                    class="pt-3 pb-1 text-xs font-bold text-muted-foreground"
                >
                    {{ STEP_GROUPS[b.group].label }}
                </li>
                <li>
                    <button
                        type="button"
                        class="w-full rounded-xl px-3 py-2 text-left transition focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none"
                        :class="
                            selected === b.key
                                ? 'bg-emerald-50 ring-1 ring-emerald-300 dark:bg-emerald-950/30 dark:ring-emerald-800'
                                : b.type === 'delta'
                                  ? 'bg-muted/40'
                                  : 'bg-card ring-1 ring-border'
                        "
                        @click="emit('select', b.key)"
                    >
                        <span class="flex items-center justify-between gap-2">
                            <span
                                class="text-xs"
                                :class="b.type === 'delta' ? '' : 'font-bold'"
                                >{{ b.type === 'delta' ? '±' : '=' }}
                                {{ b.label }}</span
                            >
                            <span
                                class="text-sm font-extrabold tabular-nums"
                                :class="
                                    b.pending
                                        ? 'text-amber-700 dark:text-amber-300'
                                        : b.value < 0
                                          ? 'text-rose-600 dark:text-rose-400'
                                          : ''
                                "
                                >{{
                                    b.pending ? 'Pendiente' : mxn(b.amount)
                                }}</span
                            >
                        </span>
                        <span
                            v-if="!b.pending"
                            class="mt-1.5 block h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <span
                                class="block h-full rounded-full"
                                :class="
                                    b.value < 0
                                        ? 'bg-rose-500'
                                        : 'bg-emerald-600'
                                "
                                :style="{
                                    width: `${(Math.abs(b.value) / maxAbs) * 100}%`,
                                }"
                            />
                        </span>
                    </button>
                </li>
            </template>
        </ol>
    </div>
</template>
