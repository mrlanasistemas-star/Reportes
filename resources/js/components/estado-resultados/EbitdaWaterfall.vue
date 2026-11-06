<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { compact, isNegative, mxn } from './types';
import type { WaterfallStep } from './types';

const props = defineProps<{ steps: WaterfallStep[] }>();

const GROUPS: Record<number, { label: string; band: string }> = {
    1: { label: 'Sucursales', band: 'bg-emerald-50/70 dark:bg-emerald-950/20' },
    2: { label: 'Corporativo', band: 'bg-cyan-50/70 dark:bg-cyan-950/20' },
    3: {
        label: 'Dirección General',
        band: 'bg-teal-50/70 dark:bg-teal-950/20',
    },
    4: {
        label: 'Contable · financiero · fiscal',
        band: 'bg-slate-50 dark:bg-slate-900/40',
    },
};
const groupOf = (level: number) => (level >= 4 ? 4 : level);

/** Barras flotantes: cada delta parte del acumulado anterior; totales/subtotales parten de 0. */
const bars = computed(() => {
    let running = 0;
    const raw = props.steps.map((s) => {
        const amount = Number(s.amount);
        let from: number;
        let to: number;

        if (s.type === 'delta') {
            from = running;
            to = running + amount;
            running = to;
        } else {
            from = 0;
            to = amount;
            running = amount;
        }

        return { ...s, from, to, value: amount };
    });
    const values = raw.flatMap((b) => [b.from, b.to, 0]);
    const rawMin = Math.min(...values);
    const rawMax = Math.max(...values);
    // Margen superior/inferior para que las etiquetas de monto no choquen con
    // las bandas de grupo ni con el borde de la gráfica.
    const pad = (rawMax - rawMin || 1) * 0.18;
    const min = rawMin < 0 ? rawMin - pad * 0.4 : rawMin;
    const max = rawMax + pad;
    const span = max - min || 1;

    return raw.map((b) => {
        const lo = Math.min(b.from, b.to);
        const hi = Math.max(b.from, b.to);

        return {
            ...b,
            bottom: ((lo - min) / span) * 100,
            height: Math.max(((hi - lo) / span) * 100, 0.8),
            zero: ((0 - min) / span) * 100,
            group: groupOf(b.level),
        };
    });
});

const groups = computed(() => {
    const out: Array<{
        id: number;
        label: string;
        band: string;
        count: number;
    }> = [];

    for (const b of bars.value) {
        const last = out[out.length - 1];

        if (last && last.id === b.group) {
            last.count++;
        } else {
            out.push({ id: b.group, ...GROUPS[b.group], count: 1 });
        }
    }

    return out;
});

function barClass(b: { type: string; value: number; key: string }): string {
    if (b.key === 'utilidad_neta') {
        return b.value < 0
            ? 'bg-gradient-to-t from-rose-700 to-rose-500'
            : 'bg-gradient-to-t from-emerald-700 via-emerald-500 to-cyan-400';
    }

    if (b.type === 'total' || b.type === 'subtotal') {
        return b.value < 0
            ? 'bg-gradient-to-t from-rose-600 to-rose-400'
            : 'bg-gradient-to-t from-teal-700 to-cyan-400';
    }

    return b.value < 0
        ? 'bg-rose-400/85 dark:bg-rose-500/70'
        : 'bg-emerald-400/90';
}
</script>

<template>
    <div class="space-y-3">
        <!-- Escritorio: cascada horizontal con bandas por nivel -->
        <div class="hidden md:block">
            <div class="flex gap-1">
                <div
                    v-for="g in groups"
                    :key="g.id + '-' + g.label"
                    class="rounded-t-xl px-2 py-1 text-center text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                    :class="g.band"
                    :style="{ flex: g.count }"
                >
                    {{ g.label }}
                </div>
            </div>
            <div class="flex gap-1">
                <div
                    v-for="b in bars"
                    :key="b.key"
                    class="relative flex-1 rounded-b-xl"
                    :class="GROUPS[b.group].band"
                >
                    <div class="relative mx-1 h-64">
                        <div
                            class="absolute inset-x-0 border-t border-dashed border-slate-300 dark:border-slate-700"
                            :style="{ bottom: `${b.zero}%` }"
                        />
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <div
                                    class="absolute inset-x-1 cursor-default rounded-md shadow-sm transition hover:brightness-110"
                                    :class="[
                                        barClass(b),
                                        b.type !== 'delta'
                                            ? 'ring-2 ring-white/70 dark:ring-slate-900/60'
                                            : '',
                                    ]"
                                    :style="{
                                        bottom: `${b.bottom}%`,
                                        height: `${b.height}%`,
                                    }"
                                />
                            </TooltipTrigger>
                            <TooltipContent>
                                <p class="font-semibold">
                                    {{ b.label }}
                                </p>
                                <p>{{ mxn(b.amount) }}</p>
                            </TooltipContent>
                        </Tooltip>
                        <div
                            class="absolute inset-x-0 text-center text-[10px] font-bold tabular-nums"
                            :class="
                                isNegative(b.amount)
                                    ? 'text-rose-600'
                                    : b.type === 'delta'
                                      ? 'text-muted-foreground'
                                      : 'text-teal-800 dark:text-teal-300'
                            "
                            :style="{
                                bottom: `calc(${b.bottom + b.height}% + 4px)`,
                            }"
                        >
                            {{ compact(b.amount) }}
                        </div>
                    </div>
                    <p
                        class="px-1 pb-2 text-center text-[10.5px] leading-tight"
                        :class="
                            b.type === 'delta'
                                ? 'text-muted-foreground'
                                : 'font-bold text-foreground'
                        "
                    >
                        {{ b.type === 'delta' ? '' : '= ' }}{{ b.label }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Móvil: cascada vertical -->
        <div class="space-y-1.5 md:hidden">
            <template v-for="(b, i) in bars" :key="b.key">
                <p
                    v-if="i === 0 || bars[i - 1].group !== b.group"
                    class="pt-2 text-[10px] font-bold tracking-wider text-muted-foreground uppercase"
                >
                    {{ GROUPS[b.group].label }}
                </p>
                <div
                    class="flex items-center gap-2 rounded-xl px-3 py-2"
                    :class="
                        b.type === 'delta'
                            ? 'bg-muted/40'
                            : 'bg-gradient-to-r from-teal-700 to-cyan-500 text-white'
                    "
                >
                    <span class="flex-1 text-xs font-semibold"
                        >{{ b.type === 'delta' ? '−/+' : '=' }}
                        {{ b.label }}</span
                    >
                    <span
                        class="text-sm font-extrabold tabular-nums"
                        :class="
                            b.type === 'delta' && isNegative(b.amount)
                                ? 'text-rose-600'
                                : ''
                        "
                        >{{ mxn(b.amount) }}</span
                    >
                </div>
            </template>
        </div>
    </div>
</template>
