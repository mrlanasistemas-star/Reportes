<script setup lang="ts">
// Plazo + trayectoria esperada (previsualización cliente, misma fórmula lineal
// que OkrTrajectoryService::expectedValue()) — nunca escribe en el servidor.
import type { ApexOptions } from 'apexcharts'
import { CalendarDays } from 'lucide-vue-next'
import { computed } from 'vue'
import AsyncApexChart from '@/components/charts/AsyncApexChart.vue'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { formatByUnit, formatFriendlyDate } from '@/lib/okrFormat'

const props = defineProps<{ startDate: string; endDate: string | null; baseline: string; target: string; unit?: string | null }>()
const weeks = defineModel<number>('weeks', { required: true })

const weekOptions = computed(() => {
    const base = [4, 6, 8, 10, 12, 16, 20, 24, 36, 48, 52]

    if (!base.includes(weeks.value)) {
        base.push(weeks.value)
    }

    return base.sort((a, b) => a - b)
})

const points = computed(() => {
    if (props.baseline === '' || props.target === '') {
        return []
    }

    const base = Number(props.baseline)
    const target = Number(props.target)
    const n = Math.max(1, weeks.value)

    return Array.from({ length: n }, (_, i) => Math.round((base + ((target - base) * (i + 1)) / n) * 100) / 100)
})
const percents = computed(() => points.value.map((_, i) => Math.round(((i + 1) / Math.max(1, weeks.value)) * 100)))

const options = computed<ApexOptions>(() => ({
    chart: { toolbar: { show: false }, animations: { enabled: true, speed: 600 } },
    xaxis: { categories: percents.value.map((_, i) => `S${i + 1}`), labels: { style: { fontSize: '10px' } }, axisTicks: { show: false } },
    yaxis: { min: 0, max: 100, tickAmount: 5, labels: { formatter: (v: number) => `${Math.round(v)}%`, style: { fontSize: '10px', colors: ['#94a3b8'] } } },
    colors: ['#6366f1'],
    stroke: { curve: 'smooth', width: 3 },
    fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 100] } },
    grid: { borderColor: 'rgba(148,163,184,0.25)', strokeDashArray: 4 },
    markers: { size: 3.5, colors: ['#ffffff'], strokeColors: '#6366f1', strokeWidth: 2, hover: { size: 6 } },
    dataLabels: { enabled: false },
    tooltip: {
        y: {
            formatter: (val: number, opts: any) => {
                const raw = points.value[opts?.dataPointIndex]

                return raw !== undefined ? `${val}% · ${formatByUnit(raw, props.unit)}` : `${val}%`
            },
        },
    },
}))
</script>

<template>
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[260px_minmax(0,1fr)]">
        <div class="app-card space-y-2 p-4 transition hover:shadow-md">
            <p class="text-xs font-semibold text-foreground">Plazo de evaluación</p>
            <p class="text-[11px] text-muted-foreground">Aplica a la sucursal y a todos los OKR individuales de esta asignación.</p>
            <div class="flex items-center gap-2 pt-1">
                <CalendarDays class="size-4 shrink-0 text-primary" />
                <Select :model-value="String(weeks)" @update:model-value="(v) => (weeks = Number(v))">
                    <SelectTrigger size="sm" class="h-9 w-full justify-between gap-1 rounded-lg border-0 bg-muted/60 px-3 text-sm font-bold text-foreground"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="w in weekOptions" :key="w" :value="String(w)">{{ w }} semanas</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="flex flex-col gap-1 pt-2 text-[11px]">
                <p><span class="font-semibold text-foreground">Inicio:</span> <span class="text-muted-foreground">{{ formatFriendlyDate(startDate) }}</span></p>
                <p><span class="font-semibold text-foreground">Término:</span> <span class="text-muted-foreground">{{ endDate ? formatFriendlyDate(endDate) : '—' }}</span></p>
            </div>
        </div>
        <div class="app-card space-y-1 p-4 transition hover:shadow-md">
            <p class="text-xs font-semibold text-foreground">Trayectoria esperada de la sucursal</p>
            <p class="text-[11px] text-muted-foreground">Avance acumulado esperado semana a semana (pasa el cursor para ver el valor).</p>
            <AsyncApexChart v-if="percents.length > 1" type="area" :height="180" :options="options" :series="[{ name: 'Avance esperado', data: percents }]" />
            <p v-else class="py-6 text-center text-[11px] text-muted-foreground">Captura base y meta para ver la trayectoria.</p>
        </div>
    </div>
</template>
