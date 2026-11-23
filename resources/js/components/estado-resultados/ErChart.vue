<script setup lang="ts">
/**
 * ErChart — envoltura de AsyncApexChart con el tema del Estado de Resultados.
 *
 * La usa TrendCharts. Combina las opciones recibidas con valores por defecto
 * (sin barra de herramientas, colores de ejes y cuadrícula, leyenda arriba a
 * la izquierda, tooltip y tema según el modo claro/oscuro) y vuelve a montar la
 * gráfica cuando cambia el modo.
 *
 * Props:
 * - type: 'line', 'bar' o 'donut'.
 * - height: alto en píxeles (300 por defecto).
 * - options: opciones de ApexCharts; tienen prioridad sobre los valores por defecto.
 * - series: series de datos.
 * - label: etiqueta accesible de la gráfica.
 */
import { useMutationObserver } from '@vueuse/core';
import { computed, onMounted, ref } from 'vue';
import AsyncApexChart from '@/components/charts/AsyncApexChart.vue';

/**
 * Gráfica ApexCharts con el tema del módulo: modo claro/oscuro, formato de
 * moneda mexicana y ejes que parten de 0 (sin escalas engañosas).
 */
const props = defineProps<{
    type: 'line' | 'bar' | 'donut';
    height?: number;
    options: Record<string, any>;
    series: any[];
    /** Etiqueta accesible: qué muestra la gráfica. */
    label: string;
}>();

/** Modo oscuro activo (clase `dark` en <html>). */
const dark = ref(false);
/** Lee si el documento está en modo oscuro. */
const read = () =>
    (dark.value = document.documentElement.classList.contains('dark'));
onMounted(read);
// Observa cambios de clase en <html> para seguir el cambio de tema.
useMutationObserver(
    typeof document !== 'undefined' ? document.documentElement : null,
    read,
    { attributes: true, attributeFilter: ['class'] },
);

/** Opciones finales: valores por defecto del tema más las opciones recibidas. */
const merged = computed(() => {
    const ink = dark.value ? '#cbd5e1' : '#475569';
    const grid = dark.value ? 'rgba(255,255,255,0.08)' : '#eef2f7';

    return {
        ...props.options,
        chart: {
            toolbar: { show: false },
            fontFamily: 'Instrument Sans, ui-sans-serif, system-ui, sans-serif',
            background: 'transparent',
            animations: { speed: 300 },
            ...(props.options.chart ?? {}),
        },
        theme: { mode: dark.value ? 'dark' : 'light' },
        grid: {
            borderColor: grid,
            strokeDashArray: 3,
            ...(props.options.grid ?? {}),
        },
        legend: {
            fontSize: '12px',
            labels: { colors: ink },
            position: 'top',
            horizontalAlign: 'left',
            ...(props.options.legend ?? {}),
        },
        tooltip: {
            theme: dark.value ? 'dark' : 'light',
            ...(props.options.tooltip ?? {}),
        },
        dataLabels: { enabled: false, ...(props.options.dataLabels ?? {}) },
        xaxis: {
            ...(props.options.xaxis ?? {}),
            labels: {
                style: { colors: ink, fontSize: '11px' },
                ...(props.options.xaxis?.labels ?? {}),
            },
        },
        yaxis: Array.isArray(props.options.yaxis)
            ? props.options.yaxis
            : {
                  ...(props.options.yaxis ?? {}),
                  labels: {
                      style: { colors: ink, fontSize: '11px' },
                      ...(props.options.yaxis?.labels ?? {}),
                  },
              },
    };
});
</script>

<template>
    <div role="img" :aria-label="label">
        <AsyncApexChart
            :key="dark ? 'd' : 'l'"
            :type="type"
            :height="height ?? 300"
            :options="merged"
            :series="series"
        />
    </div>
</template>
