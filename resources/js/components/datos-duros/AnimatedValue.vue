<script setup lang="ts">
/**
 * AnimatedValue — número que «cuenta» hasta su valor al cambiar de periodo o
 * filtro. Solo es visual: el último cuadro siempre es el valor real, y con
 * movimiento reducido salta directo.
 */
import { computed } from 'vue';
import { useCountUp } from '@/composables/estado-resultados/useCountUp';

const props = withDefaults(
    defineProps<{
        value: number;
        format: (n: number) => string;
        duration?: number;
    }>(),
    { duration: 700 },
);

const shown = useCountUp(() => props.value, props.duration);
const text = computed(() => props.format(shown.value));
</script>

<template>
    <span class="tabular-nums">{{ text }}</span>
</template>
