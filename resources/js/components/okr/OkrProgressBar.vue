<script setup lang="ts">
/**
 * OkrProgressBar — barra de progreso reutilizable para el cumplimiento de
 * KR/Objetivo y la ponderación del asistente. Se usa en OkrWeightSummary y en
 * pages/Okr/Dashboard.
 *
 * Con tono 'auto': verde al llegar al máximo, color primario desde el 60 % y
 * ámbar por debajo. Con un tono fijo usa verde, ámbar o rojo.
 *
 * Props:
 * - value: valor actual.
 * - max: valor que equivale al 100 % (por defecto 100).
 * - tone: 'auto' (según el valor) o un color fijo ('success', 'warning', 'danger').
 * - showLabel: muestra el porcentaje a la derecha de la barra.
 */
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        value: number;
        max?: number;
        tone?: 'auto' | 'success' | 'warning' | 'danger';
        showLabel?: boolean;
    }>(),
    {
        max: 100,
        tone: 'auto',
        showLabel: false,
    },
);

/** Porcentaje de llenado, acotado entre 0 y 100. */
const pct = computed(() =>
    Math.max(0, Math.min(100, (props.value / props.max) * 100)),
);

/** Clase de color de la barra según el tono fijo o, en 'auto', según el avance. */
const barClass = computed(() => {
    if (props.tone !== 'auto') {
        return {
            success: 'bg-emerald-500',
            warning: 'bg-amber-500',
            danger: 'bg-rose-500',
        }[props.tone];
    }

    if (props.value >= props.max) {
        return 'bg-emerald-500';
    }

    if (props.value >= props.max * 0.6) {
        return 'bg-primary';
    }

    return 'bg-amber-500';
});
</script>

<template>
    <div class="flex w-full items-center gap-2">
        <div class="h-2 w-full min-w-16 overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full transition-all duration-500 ease-out"
                :class="barClass"
                :style="{ width: `${pct}%` }"
            />
        </div>
        <span
            v-if="showLabel"
            class="shrink-0 text-xs font-semibold text-muted-foreground tabular-nums"
        >
            {{ Math.round(pct) }}%
        </span>
    </div>
</template>
