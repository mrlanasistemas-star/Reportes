<script setup lang="ts">
/**
 * ComparativeKpiCard — tarjeta de un KPI en el comparativo de periodos.
 *
 * Se usa en pages/ReportesMensuales/ComparativePreview. Muestra el valor del
 * periodo A (actual) en grande, el del periodo B a la derecha y la variación
 * con ComparisonDeltaBadge.
 *
 * Props:
 * - label: nombre de la métrica.
 * - prev / curr: valor del periodo B (comparado) y del periodo A (actual).
 * - diff: diferencia absoluta (A − B).
 * - varPct: variación porcentual.
 * - fmt: formato del valor ('currency', 'percent' o 'integer').
 * - labelA / labelB: nombres de los periodos.
 */
import { computed } from 'vue';
import { money, percent, num } from '@/lib/format';
import ComparisonDeltaBadge from './ComparisonDeltaBadge.vue';

const props = defineProps<{
    label: string;
    prev: number;
    curr: number;
    diff: number;
    varPct: number;
    fmt: 'currency' | 'percent' | 'integer';
    labelA: string;
    labelB: string;
}>();

/** Función que formatea un valor según `fmt` (moneda, porcentaje o entero). */
const fmtValue = computed(
    () => (v: number) =>
        props.fmt === 'percent'
            ? percent(v)
            : props.fmt === 'integer'
              ? num(v)
              : money(v),
);
</script>

<template>
    <div class="rounded-2xl border bg-card p-5 shadow-soft-xs">
        <p
            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
        >
            {{ label }}
        </p>
        <div
            class="mt-3 flex flex-wrap items-end justify-between gap-x-3 gap-y-1"
        >
            <div>
                <p
                    class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ labelA }}
                </p>
                <p
                    class="text-xl font-semibold text-foreground tabular-nums xl:text-2xl"
                >
                    {{ fmtValue(curr) }}
                </p>
            </div>
            <div class="ml-auto text-right">
                <p
                    class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ labelB }}
                </p>
                <p class="text-sm font-bold text-muted-foreground tabular-nums">
                    {{ fmtValue(prev) }}
                </p>
            </div>
        </div>
        <div class="mt-3">
            <ComparisonDeltaBadge
                :label="label"
                :diff="diff"
                :var-pct="varPct"
                :fmt="fmt"
            />
        </div>
    </div>
</template>
