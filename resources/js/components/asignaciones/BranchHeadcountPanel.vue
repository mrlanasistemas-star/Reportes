<script setup lang="ts">
import { AlertTriangle, Users } from 'lucide-vue-next'
import { computed } from 'vue'
import ChartCard from '@/components/radiography/ChartCard.vue'
import type { BranchHeadcount } from '@/types/asignaciones'

const props = defineProps<{
    distribution: BranchHeadcount[]
    hires: number
    /** Solo bajas sin contradicción (ver PeriodEmployeeRosterService::classifyBaja) — nunca el total de ausencias. */
    leavers: number
    pendingLeavers?: number
    massDropDetected?: boolean
    dropRatio?: number
}>()

const totalAsignados = computed(() => props.distribution.reduce((sum, d) => sum + d.count, 0))

const chartOptions = computed(() => ({
    chart: { toolbar: { show: false } },
    colors: ['#6366f1'],
    plotOptions: { bar: { borderRadius: 6, horizontal: true, barHeight: '62%' } },
    dataLabels: {
        enabled: true,
        style: { fontSize: '11px', fontWeight: 800, colors: ['#1e1b4b'] },
        formatter: (v: number) => String(v),
        offsetX: 18,
    },
    xaxis: {
        categories: props.distribution.map((d) => d.name),
        labels: { style: { fontSize: '11px' } },
    },
    grid: { borderColor: '#f1f5f9' },
    tooltip: { y: { formatter: (v: number) => `${v} colaborador(es)` } },
}))
const chartSeries = computed(() => [{ name: 'Colaboradores', data: props.distribution.map((d) => d.count) }])

// Pastel de altas/bajas — rotación visual del periodo, complementa las tarjetas
// de arriba (Altas/Bajas) con algo de un vistazo.
const movementTotal = computed(() => props.hires + props.leavers)
const movementOptions = computed(() => ({
    chart: { toolbar: { show: false } },
    labels: ['Altas', 'Bajas'],
    colors: ['#10b981', '#f43f5e'],
    legend: { position: 'bottom', fontSize: '12px' },
    dataLabels: { enabled: true, style: { fontSize: '12px', fontWeight: 800 }, dropShadow: { enabled: false } },
    stroke: { width: 2, colors: ['#fff'] },
    plotOptions: { pie: { donut: { size: '68%', labels: { show: true, total: { show: true, label: 'Rotación', formatter: () => String(movementTotal.value) } } } } },
    tooltip: { y: { formatter: (v: number) => `${v} colaborador(es)` } },
}))
const movementSeries = computed(() => [props.hires, props.leavers])
</script>

<template>
    <section class="app-card overflow-hidden">
        <div class="flex flex-col gap-1 border-b px-4 py-4 sm:px-6 sm:py-5">
            <div class="flex items-center gap-2">
                <Users class="size-4 text-indigo-500" />
                <h2 class="text-lg font-bold tracking-tight">Colaboradores por sucursal</h2>
            </div>
            <p class="text-sm text-muted-foreground">
                {{ totalAsignados }} colaborador(es) asignados este periodo, exactos por sucursal.
            </p>
        </div>

        <div v-if="distribution.length" class="grid gap-4 p-4 sm:p-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
            <ChartCard
                title="Distribución exacta"
                subtitle="Número de colaboradores por sucursal"
                type="bar"
                :height="Math.max(180, distribution.length * 26)"
                :series="chartSeries"
                :options="chartOptions"
            />

            <div class="flex flex-col gap-2">
                <ChartCard
                    title="Rotación del periodo"
                    subtitle="Altas vs. bajas sin contradicción (ver nota)"
                    type="donut"
                    :height="Math.max(180, distribution.length * 26)"
                    :series="movementSeries"
                    :options="movementOptions"
                />
                <p v-if="pendingLeavers" class="flex items-start gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        {{ pendingLeavers }} ausencia(s) de nómina más están pendientes de revisión (posible cambio de nombre o archivo incompleto) y
                        <strong>no</strong> se incluyen aquí como bajas — ver pestaña "Bajas del periodo".
                    </span>
                </p>
                <p v-if="massDropDetected" class="flex items-start gap-1.5 rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        La plantilla cayó {{ Math.round((dropRatio ?? 0) * 100) }}% respecto al periodo anterior — una caída así de grande
                        sugiere un archivo NOI incompleto ese mes, no bajas reales simultáneas. Verifica la carga antes de tratarlas como definitivas.
                    </span>
                </p>
            </div>
        </div>

        <div v-else class="px-4 py-10 text-center text-sm text-muted-foreground sm:px-6">
            Sin colaboradores asignados a ninguna sucursal todavía en este periodo.
        </div>
    </section>
</template>
