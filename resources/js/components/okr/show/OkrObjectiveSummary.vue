<script setup lang="ts">
// Resumen del objetivo (15-oct-2026): SOLO los indicadores principales del KR
// principal + cumplimiento ponderado. Para colocación con carga semanal, el
// "real" es lo colocado en el periodo y el incremento se compara contra la
// base proporcional a las semanas transcurridas.
import { computed } from 'vue'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import { formatByUnit } from '@/lib/okrFormat'

const props = defineProps<{ objective: any; keyResults: any[] }>()

const kr = computed(() => props.keyResults[0] ?? null)
const unit = computed(() => kr.value?.kpi?.unit)
const isWeeklyFlow = computed(() => kr.value?.kpi?.type === 'cumulative' && (kr.value?.snapshots ?? []).some((s: any) => s.source_granularity === 'weekly'))

function signed(n: number | null): string {
    if (n === null || Number.isNaN(n)) {
        return '—'
    }

    return unit.value === 'percentage' ? `${n > 0 ? '+' : ''}${n.toFixed(2)} pp` : `${n > 0 ? '+' : ''}${formatByUnit(n, unit.value)}`
}

const expectedIncrement = computed(() => kr.value && kr.value.baseline_value !== null ? Number(kr.value.target_value) - Number(kr.value.baseline_value) : null)
const achievedIncrement = computed(() => {
    if (!kr.value || kr.value.current_value === null || kr.value.baseline_value === null) {
        return null
    }

    if (isWeeklyFlow.value) {
        // Proporción a la ÚLTIMA semana con dato real (no a la semana en curso sin archivo).
        const lastWeek = [...(kr.value.snapshots ?? [])].reverse().find((s: any) => s.actual_value !== null)?.week_number ?? props.objective.current_week ?? 0
        const fraction = Math.min(1, lastWeek / Math.max(1, props.objective.duration_weeks))

        return Number(kr.value.current_value) - Number(kr.value.baseline_value) * fraction
    }

    return Number(kr.value.current_value) - Number(kr.value.baseline_value)
})

const tiles = computed(() => [
    { label: 'Línea base', value: kr.value ? formatByUnit(kr.value.baseline_value, unit.value) : '—', hint: kr.value?.baseline_locked_at ? 'Congelada' : 'Se congela al activar' },
    { label: 'Meta final', value: kr.value ? formatByUnit(kr.value.target_value, unit.value) : '—', hint: kr.value?.kpi?.name ?? '' },
    { label: 'Incremento esperado', value: signed(expectedIncrement.value), hint: 'Meta − base' },
    { label: isWeeklyFlow.value ? 'Colocado en el periodo' : 'Valor actual', value: kr.value ? formatByUnit(kr.value.current_value, unit.value) : '—', hint: kr.value?.current_value === null ? 'Sin datos todavía' : 'Acumulado real' },
    { label: 'Incremento alcanzado', value: signed(achievedIncrement.value), hint: isWeeklyFlow.value ? 'vs. base proporcional a la fecha' : 'Actual − base' },
    { label: 'Cumplimiento', value: `${props.objective.compliance ?? 0}%`, hint: `Esperado ${props.objective.expected_compliance ?? 0}%` },
])
</script>

<template>
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-7">
        <div v-for="t in tiles" :key="t.label" class="group rounded-2xl border border-border bg-card p-3.5 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-lg">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ t.label }}</p>
            <p class="mt-1 text-lg font-bold tabular-nums text-foreground">{{ t.value }}</p>
            <p class="truncate text-[11px] text-muted-foreground">{{ t.hint }}</p>
        </div>
        <div class="rounded-2xl border border-border bg-card p-3.5 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:shadow-lg">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Estado</p>
            <div class="mt-1.5 flex flex-wrap gap-1">
                <OkrStatusBadge kind="lifecycle" :value="objective.lifecycle_status" />
                <OkrStatusBadge v-if="objective.health_status" kind="health" :value="objective.health_status" />
            </div>
            <p class="mt-1 text-[11px] text-muted-foreground">Semana {{ objective.current_week || 0 }} / {{ objective.duration_weeks }}</p>
        </div>
    </section>
</template>
