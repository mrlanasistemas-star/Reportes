<script setup lang="ts">
// Histórico semanal por KR (snapshots congelados semana a semana): esperado
// vs real, cumplimiento y semáforo. Las semanas sin dato se muestran como
// "sin dato" — nunca como 0.
import { CalendarRange } from 'lucide-vue-next'
import { ref } from 'vue'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import { formatByUnit, formatPp } from '@/lib/okrFormat'

const props = defineProps<{ keyResults: any[] }>()
const selected = ref<number | null>(props.keyResults[0]?.id ?? null)
</script>

<template>
    <div v-if="keyResults.some((kr) => (kr.snapshots ?? []).length)" class="app-card p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <p class="flex items-center gap-1.5 text-sm font-bold text-foreground"><CalendarRange class="size-4 text-primary" /> Histórico semanal</p>
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="kr in keyResults" :key="kr.id" type="button"
                    class="rounded-full px-2.5 py-1 text-[11px] font-semibold transition hover:-translate-y-0.5"
                    :class="selected === kr.id ? 'bg-primary text-primary-foreground shadow' : 'bg-muted text-muted-foreground hover:bg-muted/70'"
                    @click="selected = kr.id"
                >{{ kr.kpi.name }}</button>
            </div>
        </div>
        <template v-for="kr in keyResults" :key="kr.id">
            <div v-if="kr.id === selected" class="overflow-x-auto rounded-xl border border-border animate-in fade-in duration-200">
                <table class="w-full min-w-[620px] text-xs">
                    <thead class="bg-muted/30 text-muted-foreground">
                        <tr>
                            <th class="px-2.5 py-2 text-left font-semibold">Semana</th>
                            <th class="px-2.5 py-2 text-right font-semibold">Esperado</th>
                            <th class="px-2.5 py-2 text-right font-semibold">Real acumulado</th>
                            <th class="px-2.5 py-2 text-right font-semibold">Cumplimiento</th>
                            <th class="px-2.5 py-2 text-right font-semibold">Desviación</th>
                            <th class="px-2.5 py-2 text-left font-semibold">Semáforo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in kr.snapshots" :key="s.week_number" class="border-t border-border transition-colors hover:bg-muted/30">
                            <td class="px-2.5 py-2 font-semibold text-foreground">S{{ s.week_number }}</td>
                            <td class="px-2.5 py-2 text-right tabular-nums text-muted-foreground">{{ formatByUnit(s.expected_value, kr.kpi.unit) }}</td>
                            <td class="px-2.5 py-2 text-right font-semibold tabular-nums">
                                <span v-if="s.actual_value === null" class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-700 dark:text-amber-300">Sin dato</span>
                                <span v-else>{{ formatByUnit(s.actual_value, kr.kpi.unit) }}</span>
                            </td>
                            <td class="px-2.5 py-2 text-right tabular-nums">{{ s.actual_progress_percentage === null ? '—' : `${s.actual_progress_percentage}%` }}</td>
                            <td class="px-2.5 py-2 text-right font-semibold tabular-nums" :class="(s.deviation_pp ?? 0) < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">{{ formatPp(s.deviation_pp) }}</td>
                            <td class="px-2.5 py-2"><OkrStatusBadge v-if="s.health_status" kind="health" :value="s.health_status" /><span v-else class="text-muted-foreground">—</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
