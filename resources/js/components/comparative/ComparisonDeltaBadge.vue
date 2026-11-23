<script setup lang="ts">
/**
 * ComparisonDeltaBadge — insignia con la diferencia y la variación % de una métrica.
 *
 * La usa ComparativeKpiCard. El color (verde/rojo/gris) depende de si el
 * cambio es favorable para esa métrica (toneFor).
 *
 * Props:
 * - label: nombre de la métrica (define si subir es bueno o malo).
 * - diff: diferencia absoluta.
 * - varPct: variación porcentual (define la flecha).
 * - fmt: formato de la diferencia ('currency', 'percent' o 'integer').
 */
import { ArrowUp, ArrowDown, Minus } from 'lucide-vue-next';
import { computed } from 'vue';
import { toneFor } from '@/lib/comparative-metrics';
import { money, percent, num } from '@/lib/format';

const props = defineProps<{
    label: string;
    diff: number;
    varPct: number;
    fmt: 'currency' | 'percent' | 'integer';
}>();

/** Tono de la variación: 'good', 'bad' o 'neutral'. */
const tone = computed(() => toneFor(props.label, props.varPct));

/** Clases de color según el tono. */
const toneClasses = computed(
    () =>
        ({
            good: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
            bad: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
            neutral: 'bg-muted text-muted-foreground  ',
        })[tone.value],
);

/** Diferencia formateada con signo (+ / −). */
const diffLabel = computed(() => {
    const abs = Math.abs(props.diff);
    const formatted =
        props.fmt === 'percent'
            ? percent(abs)
            : props.fmt === 'integer'
              ? num(abs)
              : money(abs);
    const sign = props.diff > 0 ? '+' : props.diff < 0 ? '−' : '';

    return `${sign}${formatted}`;
});
</script>

<template>
    <span
        :class="toneClasses"
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold"
    >
        <ArrowUp v-if="varPct > 0" class="size-3" />
        <ArrowDown v-else-if="varPct < 0" class="size-3" />
        <Minus v-else class="size-3" />
        {{ diffLabel }}
        <span class="opacity-70"
            >({{ varPct >= 0 ? '+' : '' }}{{ varPct.toFixed(2) }}%)</span
        >
    </span>
</template>
