<script setup lang="ts">
// "Cumplimiento general" — reproduce docs/imagenesOKR/1.png y 2.png: donut +
// leyenda propia (color · etiqueta · porcentaje) + total debajo. 4 buckets
// que son el semáforo YA existente (ahead/on_track/risk/off_track) — nunca
// una clasificación nueva (ver DashboardController::complianceBreakdown()).
import { computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'

const props = defineProps<{
    breakdown: {
        total: number
        completed: number; completed_pct: number
        on_track: number; on_track_pct: number
        at_risk: number; at_risk_pct: number
        off_track: number; off_track_pct: number
    }
    /** % de cumplimiento promedio real (DashboardController::index() → cards.avg_compliance) — nunca inventado aquí. */
    avgCompliance: number
}>()

const COLORS = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444']

const series = computed(() => [props.breakdown.completed, props.breakdown.on_track, props.breakdown.at_risk, props.breakdown.off_track])

const options = computed(() => ({
    chart: { toolbar: { show: false } },
    labels: ['Cumplidos', 'En trayectoria', 'En riesgo', 'No cumplidos'],
    colors: COLORS,
    legend: { show: false },
    dataLabels: { enabled: false },
    stroke: { width: 0 },
    plotOptions: { pie: { donut: { size: '72%', labels: {
        show: true,
        value: { formatter: () => `${props.avgCompliance}%` },
        total: { show: true, label: 'Cumplimiento', formatter: () => `${props.avgCompliance}%` },
    } } } },
    tooltip: { y: { formatter: (v: number) => `${v} OKR` } },
}))

const legend = computed(() => [
    { label: 'Cumplidos', pct: props.breakdown.completed_pct, color: COLORS[0] },
    { label: 'En trayectoria', pct: props.breakdown.on_track_pct, color: COLORS[1] },
    { label: 'En riesgo', pct: props.breakdown.at_risk_pct, color: COLORS[2] },
    { label: 'No cumplidos', pct: props.breakdown.off_track_pct, color: COLORS[3] },
])
</script>

<template>
    <div class="app-card p-4">
        <p class="mb-3 text-sm font-bold text-foreground">Cumplimiento general</p>
        <div v-if="breakdown.total > 0" class="flex items-center gap-4">
            <div class="w-32 shrink-0">
                <VueApexCharts type="donut" :height="128" :options="options" :series="series" />
            </div>
            <div class="min-w-0 flex-1 space-y-1.5">
                <div v-for="item in legend" :key="item.label" class="flex items-center justify-between gap-2 text-xs">
                    <span class="flex min-w-0 items-center gap-1.5">
                        <span class="size-2 shrink-0 rounded-full" :style="{ backgroundColor: item.color }" />
                        <span class="truncate text-muted-foreground">{{ item.label }}</span>
                    </span>
                    <span class="shrink-0 font-semibold tabular-nums text-foreground">{{ item.pct }}%</span>
                </div>
            </div>
        </div>
        <p v-else class="py-6 text-center text-xs text-muted-foreground">Sin OKR activos en este alcance.</p>
        <div class="mt-3 flex items-center justify-between border-t border-border pt-2 text-xs">
            <span class="text-muted-foreground">Total OKR activos</span>
            <span class="font-bold tabular-nums text-foreground">{{ breakdown.total }}</span>
        </div>
    </div>
</template>
