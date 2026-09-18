<script setup lang="ts">
import { computed } from 'vue'
import VueApexCharts from 'vue3-apexcharts'
import { useAppearance } from '@/composables/useAppearance'

const props = defineProps<{
    title: string
    subtitle?: string
    type?: 'bar' | 'donut' | 'line' | 'area' | 'radialBar'
    height?: number
    series: any[]
    options: Record<string, any>
    loading?: boolean
}>()

const isEmpty = computed(() => {
    if (!props.series || props.series.length === 0) return true
    const total = props.series.reduce((sum: number, s: any) => {
        // Donut/pie: series is numbers[] — each element IS the value
        if (typeof s === 'number') return sum + Math.abs(s)
        // Bar/line: series is [{name, data: numbers[]}]
        const data = Array.isArray(s) ? s : (s?.data ?? [])
        return sum + (data as number[]).reduce((a: number, b: number) => a + Math.abs(Number(b) || 0), 0)
    }, 0)
    return total === 0
})

// ApexCharts no sigue el .dark de Tailwind solo — necesita colores explícitos.
// En vez de que cada página que arma sus `options` tenga que acordarse de eso,
// ChartCard mezcla un tema oscuro/claro consistente por encima de lo que mande
// la página (grid/leyenda/tooltip), sin tocar los colores de marca que sí trae
// cada serie (esos se quedan igual en cualquier tema).
const { resolvedAppearance } = useAppearance()
const isDark = computed(() => resolvedAppearance.value === 'dark')

const themedOptions = computed(() => {
    const o = props.options ?? {}
    const labelColor = isDark.value ? '#94a3b8' : (o.xaxis?.labels?.style?.colors ?? '#64748b')

    return {
        ...o,
        theme: { mode: isDark.value ? 'dark' : 'light', ...o.theme },
        grid: { borderColor: isDark.value ? 'rgba(255,255,255,0.08)' : '#f1f5f9', ...o.grid },
        tooltip: { theme: isDark.value ? 'dark' : 'light', ...o.tooltip },
        legend: { labels: { colors: labelColor }, ...o.legend },
        xaxis: {
            ...o.xaxis,
            labels: { ...o.xaxis?.labels, style: { colors: labelColor, ...o.xaxis?.labels?.style } },
        },
        yaxis: {
            ...o.yaxis,
            labels: { ...o.yaxis?.labels, style: { colors: labelColor, ...o.yaxis?.labels?.style } },
        },
    }
})
</script>

<template>
    <div class="rounded-2xl border bg-card p-5 text-card-foreground shadow-sm transition duration-200 hover:shadow-md dark:hover:shadow-black/20">
        <div class="mb-3">
            <h3 class="text-xs font-black tracking-wider text-muted-foreground uppercase">{{ title }}</h3>
            <p v-if="subtitle" class="mt-0.5 text-[11px] text-muted-foreground/80">{{ subtitle }}</p>
        </div>
        <div
            v-if="loading"
            class="flex flex-col items-center justify-center gap-2 text-center text-xs text-muted-foreground"
            :style="{ height: (height ?? 260) + 'px' }"
        >
            <span class="size-5 animate-spin rounded-full border-2 border-border border-t-indigo-500"></span>
            Cargando…
        </div>
        <div v-else-if="isEmpty" class="flex items-center justify-center text-center text-xs text-muted-foreground" :style="{ height: (height ?? 260) + 'px' }">
            Sin datos disponibles para este periodo.
        </div>
        <VueApexCharts v-else :type="type ?? 'bar'" :height="height ?? 260" :options="themedOptions" :series="series" />
    </div>
</template>
