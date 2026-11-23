<script setup lang="ts">
/**
 * AsyncApexChart — envoltura de ApexCharts con carga diferida de la librería.
 *
 * Lo usan ErChart, OkrComplianceDonut, OkrTrajectoryPanel y ChartCard.
 *
 * Props (se reenvían tal cual a VueApexCharts):
 * - type: tipo de gráfica (bar, donut, line, area, radialBar, pie).
 * - height / width: dimensiones.
 * - options: configuración de ApexCharts.
 * - series: datos de la gráfica.
 */
// Carga vue3-apexcharts (~590 KB) solo cuando este componente realmente se
// monta — páginas que nunca renderizan una gráfica (Login, listados sin
// datos, estados vacíos) nunca descargan la librería. Wrapper delgado:
// mismos props/eventos que VueApexCharts, sin lógica propia.
import { defineAsyncComponent } from 'vue';

defineProps<{
    type?: 'bar' | 'donut' | 'line' | 'area' | 'radialBar' | 'pie';
    height?: number | string;
    width?: number | string;
    options: Record<string, any>;
    series: any[];
}>();

/** Componente de ApexCharts importado bajo demanda (solo se descarga al montarse). */
const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'));
</script>

<template>
    <VueApexCharts
        :type="type"
        :height="height"
        :width="width"
        :options="options"
        :series="series"
    />
</template>
