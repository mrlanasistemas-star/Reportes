<script setup lang="ts">
// Resumen del objetivo (15-oct-2026): SOLO los indicadores principales del KR
// principal + cumplimiento ponderado. Para colocación con carga semanal, el
// "real" es lo colocado en el periodo y el incremento se compara contra la
// base proporcional a las semanas transcurridas.
import { computed } from 'vue'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
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
    { label: 'Línea base', value: kr.value ? formatByUnit(kr.value.baseline_value, unit.value) : '—', hint: kr.value?.baseline_locked_at ? 'Congelada' : 'Se congela al activar', help: 'Es el valor inicial que se conservará al activar este objetivo. Cuando activas el OKR se congela para comparar tus avances.', tourKey: 'okr-baseline' },
    { label: 'Meta final', value: kr.value ? formatByUnit(kr.value.target_value, unit.value) : '—', hint: kr.value?.kpi?.name ?? '', help: 'Es el resultado que quieres alcanzar al terminar el plazo de este objetivo.', tourKey: 'okr-goal' },
    { label: 'Incremento esperado', value: signed(expectedIncrement.value), hint: 'Meta − base', help: 'Es cuánto deseas mejorar respecto a tu línea base (meta menos línea base).', tourKey: 'okr-increment' },
    { label: isWeeklyFlow.value ? 'Colocado en el periodo' : 'Valor actual', value: kr.value ? formatByUnit(kr.value.current_value, unit.value) : '—', hint: kr.value?.current_value === null ? 'Sin datos todavía' : 'Acumulado real', help: 'El dato real más reciente que Reportería tiene de este KPI.' },
    { label: 'Incremento alcanzado', value: signed(achievedIncrement.value), hint: isWeeklyFlow.value ? 'vs. base proporcional a la fecha' : 'Actual − base', help: 'Cuánto has avanzado hasta ahora respecto a tu línea base.' },
    { label: 'Cumplimiento', value: `${props.objective.compliance ?? 0}%`, hint: `Esperado ${props.objective.expected_compliance ?? 0}%`, help: 'Muestra cuánto deberías haber avanzado hasta la fecha de acuerdo con tu meta y plazo, comparado contra tu avance real.' },
])
</script>

<template>
    <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-7">
        <div v-for="t in tiles" :key="t.label" :data-tour="t.tourKey" class="group rounded-2xl border border-border bg-card p-3.5 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:border-primary/30 hover:shadow-lg">
            <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ t.label }}<OkrHelpTooltip v-if="t.help" :text="t.help" /></p>
            <p class="mt-1 text-lg font-bold tabular-nums text-foreground">{{ t.value }}</p>
            <p class="truncate text-[11px] text-muted-foreground">{{ t.hint }}</p>
        </div>
        <div class="rounded-2xl border border-border bg-card p-3.5 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:shadow-lg">
            <p class="flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Estado<OkrHelpTooltip text="El semáforo indica visualmente si estás cumpliendo la meta: verde vas bien, amarillo alerta, rojo necesitas corregir." /></p>
            <div class="mt-1.5 flex flex-wrap gap-1">
                <OkrStatusBadge kind="lifecycle" :value="objective.lifecycle_status" />
                <OkrStatusBadge v-if="objective.health_status" kind="health" :value="objective.health_status" />
            </div>
            <p class="mt-1 text-[11px] text-muted-foreground">Semana {{ objective.current_week || 0 }} / {{ objective.duration_weeks }}</p>
        </div>
    </section>
</template>
