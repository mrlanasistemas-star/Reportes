<script setup lang="ts">
// Carga vue3-apexcharts (~590 KB) solo cuando este componente realmente se
// monta — páginas que nunca renderizan una gráfica (Login, listados sin
// datos, estados vacíos) nunca descargan la librería. Wrapper delgado:
// mismos props/eventos que VueApexCharts, sin lógica propia.
import { defineAsyncComponent } from 'vue'

defineProps<{
    type?: 'bar' | 'donut' | 'line' | 'area' | 'radialBar' | 'pie'
    height?: number | string
    width?: number | string
    options: Record<string, any>
    series: any[]
}>()

const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'))
</script>

<template>
    <VueApexCharts :type="type" :height="height" :width="width" :options="options" :series="series" />
</template>
