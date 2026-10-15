<script setup lang="ts">
// Resultados por KPI (15-oct-2026): base, meta, actual, esperado, desviación,
// cumplimiento, proyección y semáforo con una explicación sencilla. La
// proyección solo aparece cuando hay información semanal que la sustente.
import { Info, PencilLine, Trash2, TrendingDown, TrendingUp } from 'lucide-vue-next'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import { formatByUnit, formatPp } from '@/lib/okrFormat'

defineProps<{ keyResults: any[]; lifecycle: string; canUpdate: boolean }>()
const emit = defineEmits<{ edit: [kr: any]; remove: [kr: any] }>()

const SOURCE: Record<string, string> = {
    exact: 'Colocación semanal real cargada en el OKR.',
    monthly_proxy: 'Total MENSUAL de Reportería (el sistema no tiene dato semanal para este KPI): no cambia entre semanas del mismo mes y no se proyecta.',
    last_available: 'Reportería aún no cierra el mes de esta semana: se muestra el último mes disponible (no sirve para evaluar incumplimiento).',
    missing: 'Falta el archivo de alguna semana: no se calcula el acumulado ni se finge un cero.',
    manual_checkin: 'Capturado en el check-in semanal.',
}

function latest(kr: any): any {
    const s = kr.snapshots ?? []

    return s.length ? s[s.length - 1] : null
}

function explain(kr: any): string {
    if (kr.current_value === null) {
        return 'Sin datos todavía para esta semana.'
    }

    const dev = Number(kr.deviation_pp ?? 0)

    return dev >= 0 ? `Va ${dev.toFixed(1)} pp por encima de lo esperado a la fecha.` : `Va ${Math.abs(dev).toFixed(1)} pp por debajo de lo esperado a la fecha.`
}
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[980px] text-xs">
            <thead class="bg-muted/40 text-muted-foreground">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold">KPI</th>
                    <th class="px-3 py-2 text-right font-semibold"><span class="inline-flex items-center gap-1 justify-end">Línea base<OkrHelpTooltip text="Es el valor inicial que se conservará al activar este objetivo. A partir de ahí se mide todo el avance." /></span></th>
                    <th class="px-3 py-2 text-right font-semibold">Meta</th>
                    <th class="px-3 py-2 text-right font-semibold">Actual</th>
                    <th class="px-3 py-2 text-right font-semibold"><span class="inline-flex items-center gap-1 justify-end">Esperado a la fecha<OkrHelpTooltip text="Muestra cuánto deberías haber avanzado hasta hoy de acuerdo con tu meta y el plazo del objetivo." /></span></th>
                    <th class="px-3 py-2 text-right font-semibold"><span class="inline-flex items-center gap-1 justify-end">Desviación<OkrHelpTooltip text="Es la diferencia entre tu progreso real y el esperado a la fecha. Positiva = vas adelantado; negativa = vas atrasado." /></span></th>
                    <th class="px-3 py-2 text-left font-semibold"><span class="inline-flex items-center gap-1">Cumplimiento<OkrHelpTooltip text="Qué porcentaje de la meta ya alcanzaste, comparado contra tu línea base." /></span></th>
                    <th class="px-3 py-2 text-right font-semibold"><span class="inline-flex items-center gap-1 justify-end">Proyección<OkrHelpTooltip text="Con el ritmo de las últimas semanas, dónde terminarías al final del plazo si todo sigue igual." /></span></th>
                    <th class="px-3 py-2 text-left font-semibold"><span class="inline-flex items-center gap-1">Semáforo<OkrHelpTooltip text="Indica visualmente si estás cumpliendo la meta: verde vas bien, amarillo alerta, rojo necesitas corregir." /></span></th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="kr in keyResults" :key="kr.id" class="border-t border-border align-top transition-colors hover:bg-muted/30">
                    <td class="max-w-[220px] px-3 py-3">
                        <p class="font-bold text-foreground">{{ kr.kpi.name }} <span class="font-normal text-muted-foreground">· {{ kr.weight }}%</span></p>
                        <p class="truncate text-[11px] text-muted-foreground">{{ kr.description }}</p>
                        <p class="mt-1 text-[10px] text-muted-foreground">{{ explain(kr) }}</p>
                    </td>
                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">{{ formatByUnit(kr.baseline_value, kr.kpi.unit) }}</td>
                    <td class="whitespace-nowrap px-3 py-3 text-right font-bold tabular-nums text-primary">{{ formatByUnit(kr.target_value, kr.kpi.unit) }}</td>
                    <td class="whitespace-nowrap px-3 py-3 text-right font-semibold tabular-nums">
                        <span class="inline-flex items-center gap-1">{{ formatByUnit(kr.current_value, kr.kpi.unit) }}<OkrHelpTooltip v-if="SOURCE[latest(kr)?.source_quality ?? '']" :text="SOURCE[latest(kr)?.source_quality ?? '']" /></span>
                    </td>
                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums text-muted-foreground">{{ formatByUnit(kr.expected_value, kr.kpi.unit) }}</td>
                    <td class="whitespace-nowrap px-3 py-3 text-right font-semibold tabular-nums" :class="(kr.deviation_pp ?? 0) < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">
                        <span class="inline-flex items-center gap-1"><component :is="(kr.deviation_pp ?? 0) >= 0 ? TrendingUp : TrendingDown" class="size-3.5" />{{ formatPp(kr.deviation_pp) }}</span>
                    </td>
                    <td class="w-40 px-3 py-3">
                        <div class="flex items-center gap-2">
                            <div class="relative h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <div class="absolute inset-y-0 left-0 rounded-full bg-gradient-to-r from-indigo-500 to-emerald-500 transition-all duration-700" :style="{ width: `${Math.max(0, Math.min(100, kr.actual_progress_percentage ?? 0))}%` }" />
                                <div v-if="kr.expected_progress_percentage !== null" class="absolute inset-y-0 w-0.5 bg-foreground/60" :style="{ left: `${Math.max(0, Math.min(100, kr.expected_progress_percentage))}%` }" title="Esperado a la fecha" />
                            </div>
                            <span class="w-12 text-right font-bold tabular-nums">{{ kr.actual_progress_percentage === null ? '—' : `${kr.actual_progress_percentage}%` }}</span>
                        </div>
                    </td>
                    <td class="whitespace-nowrap px-3 py-3 text-right tabular-nums">
                        <span v-if="kr.projected_value === null" class="inline-flex items-center gap-1 text-[11px] text-muted-foreground"><Info class="size-3" /> Sin datos suficientes</span>
                        <span v-else>{{ formatByUnit(kr.projected_value, kr.kpi.unit) }}</span>
                    </td>
                    <td class="px-3 py-3"><OkrStatusBadge v-if="kr.health_status" kind="health" :value="kr.health_status" /><span v-else class="text-muted-foreground">—</span></td>
                    <td class="px-3 py-3 text-right">
                        <button v-if="lifecycle === 'active' && canUpdate" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-bold text-primary transition hover:bg-primary/10" @click="emit('edit', kr)"><PencilLine class="size-3.5" /> Editar</button>
                        <button v-if="lifecycle === 'draft' && canUpdate" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-bold text-destructive transition hover:bg-destructive/10" @click="emit('remove', kr)"><Trash2 class="size-3.5" /> Quitar</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
