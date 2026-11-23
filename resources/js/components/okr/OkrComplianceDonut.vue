<script setup lang="ts">
/**
 * OkrComplianceDonut — tarjeta "Cumplimiento general" del tablero OKR
 * (reproduce docs/imagenesOKR/1.png y 2.png): donut + leyenda propia
 * (color · etiqueta · porcentaje) + total debajo. Los 4 grupos son el semáforo
 * YA existente, nunca una clasificación nueva (ver
 * DashboardController::complianceBreakdown()). Se usa en pages/Okr/Dashboard.
 *
 * Props:
 * - breakdown: total de OKR y conteo/porcentaje por grupo (cumplidos, en
 *   trayectoria, en riesgo, no cumplidos).
 * - avgCompliance: % de cumplimiento promedio que se muestra al centro.
 */
import { computed } from 'vue';
import AsyncApexChart from '@/components/charts/AsyncApexChart.vue';
import { themedChartOptions, useChartDarkMode } from '@/lib/chart-theme';

const props = defineProps<{
    breakdown: {
        total: number;
        completed: number;
        completed_pct: number;
        on_track: number;
        on_track_pct: number;
        at_risk: number;
        at_risk_pct: number;
        off_track: number;
        off_track_pct: number;
    };
    /** % de cumplimiento promedio real (DashboardController::index() → cards.avg_compliance) — nunca inventado aquí. */
    avgCompliance: number;
}>();

/** Colores de los grupos: cumplidos, en trayectoria, en riesgo, no cumplidos. */
const COLORS = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'];

/** Datos del donut: número de OKR por grupo. */
const series = computed(() => [
    props.breakdown.completed,
    props.breakdown.on_track,
    props.breakdown.at_risk,
    props.breakdown.off_track,
]);

/** Opciones de ApexCharts (sin leyenda nativa; total al centro = cumplimiento promedio). */
const options = computed(() => ({
    chart: { toolbar: { show: false } },
    labels: ['Cumplidos', 'En trayectoria', 'En riesgo', 'No cumplidos'],
    colors: COLORS,
    legend: { show: false },
    dataLabels: { enabled: false },
    stroke: { width: 0 },
    plotOptions: {
        pie: {
            donut: {
                size: '72%',
                labels: {
                    show: true,
                    value: { formatter: () => `${props.avgCompliance}%` },
                    total: {
                        show: true,
                        label: 'Cumplimiento',
                        formatter: () => `${props.avgCompliance}%`,
                    },
                },
            },
        },
    },
    tooltip: { y: { formatter: (v: number) => `${v} OKR` } },
}));

/** Leyenda propia: etiqueta, porcentaje y color de cada grupo. */
const legend = computed(() => [
    {
        label: 'Cumplidos',
        pct: props.breakdown.completed_pct,
        color: COLORS[0],
    },
    {
        label: 'En trayectoria',
        pct: props.breakdown.on_track_pct,
        color: COLORS[1],
    },
    { label: 'En riesgo', pct: props.breakdown.at_risk_pct, color: COLORS[2] },
    {
        label: 'No cumplidos',
        pct: props.breakdown.off_track_pct,
        color: COLORS[3],
    },
]);

// Tema claro/oscuro: mismos datos, colores de ejes/leyenda/tooltip desde los tokens.
const isDark = useChartDarkMode();
/** Opciones del gráfico ajustadas al tema actual. */
const themedOptions = computed(() =>
    themedChartOptions(
        options.value as Record<string, any>,
        isDark.value,
        'donut',
    ),
);
</script>

<template>
    <div class="app-card p-4">
        <p class="mb-3 text-sm font-bold text-foreground">
            Cumplimiento general
        </p>
        <!-- Donut y leyenda -->
        <div v-if="breakdown.total > 0" class="flex items-center gap-4">
            <div class="w-32 shrink-0">
                <AsyncApexChart
                    type="donut"
                    :height="128"
                    :options="themedOptions"
                    :series="series"
                />
            </div>
            <div class="min-w-0 flex-1 space-y-1.5">
                <div
                    v-for="item in legend"
                    :key="item.label"
                    class="flex items-center justify-between gap-2 text-xs"
                >
                    <span class="flex min-w-0 items-center gap-1.5">
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :style="{ backgroundColor: item.color }"
                        />
                        <span class="truncate text-muted-foreground">{{
                            item.label
                        }}</span>
                    </span>
                    <span
                        class="shrink-0 font-semibold text-foreground tabular-nums"
                        >{{ item.pct }}%</span
                    >
                </div>
            </div>
        </div>
        <p v-else class="py-6 text-center text-xs text-muted-foreground">
            Sin OKR activos en este alcance.
        </p>
        <!-- Total de OKR activos -->
        <div
            class="mt-3 flex items-center justify-between border-t border-border pt-2 text-xs"
        >
            <span class="text-muted-foreground">Total OKR activos</span>
            <span class="font-bold text-foreground tabular-nums">{{
                breakdown.total
            }}</span>
        </div>
    </div>
</template>
