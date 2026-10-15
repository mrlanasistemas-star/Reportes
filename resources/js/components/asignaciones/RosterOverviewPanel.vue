<script setup lang="ts">
import { AlertTriangle } from 'lucide-vue-next'
import { computed, toRef } from 'vue'
import AppSectionCard from '@/components/app/AppSectionCard.vue'
import RosterListPager from '@/components/asignaciones/RosterListPager.vue'
import RosterListToolbar from '@/components/asignaciones/RosterListToolbar.vue'
import RosterSourcesNote from '@/components/asignaciones/RosterSourcesNote.vue'
import { statusClasses } from '@/components/asignaciones/rosterStatus'
import { useRosterList } from '@/components/asignaciones/useRosterList'
import type { LaborRosterSummary, RosterMovementItem } from '@/types/asignaciones'

const props = defineProps<{
    roster: LaborRosterSummary
    active: RosterMovementItem[]
    periodLabel?: string | null
}>()

const list = useRosterList(toRef(props, 'active'), 12)
const { query, category, branch, page } = list

const variation = computed(() => props.roster.plantilla - props.roster.plantilla_anterior)
const subtitle = computed(
    () =>
        `Personas en la nómina NOI de ${props.periodLabel || 'este periodo'}${props.roster.periodo_rango ? ` (${props.roster.periodo_rango})` : ''}. ` +
        'Estar en nómina indica presencia; la vigencia laboral se confirma con el directorio del periodo.',
)
</script>

<template>
    <AppSectionCard title="Plantilla del periodo" :subtitle="subtitle" :padded="false">
        <div v-if="!roster.calculado" class="px-5 py-10 text-center text-sm text-muted-foreground">
            La plantilla de este periodo aún no se ha calculado: ejecuta "Actualizar BD" en Histórico General.
        </div>

        <template v-else>
            <div class="space-y-3 border-b border-border p-4 sm:p-5">
                <p class="text-sm text-muted-foreground">
                    <span class="font-semibold text-foreground tabular-nums">{{ roster.plantilla }}</span> personas este periodo
                    <template v-if="roster.periodo_anterior">
                        · <span class="tabular-nums">{{ roster.plantilla_anterior }}</span> en {{ roster.periodo_anterior }}
                        <span v-if="roster.plantilla_anterior" class="font-semibold tabular-nums" :class="variation < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">
                            ({{ variation > 0 ? '+' : '' }}{{ variation }})
                        </span>
                    </template>
                </p>
                <RosterSourcesNote :roster="roster" />

                <p v-if="roster.mass_drop_detected" class="flex items-start gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        La plantilla cayó {{ Math.round(roster.drop_ratio * 100) }}% respecto al periodo anterior. Una caída así suele indicar un archivo NOI
                        incompleto, no bajas simultáneas: las ausencias quedan por conciliar, nunca como bajas confirmadas.
                    </span>
                </p>
                <p v-if="roster.sin_clasificar" class="flex items-start gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    <span>
                        {{ roster.sin_clasificar }} ausencia(s) vienen de un cálculo anterior a la conciliación y no están clasificadas. Ejecuta "Actualizar BD"
                        del periodo para clasificarlas; mientras tanto no cuentan como bajas confirmadas.
                    </span>
                </p>
            </div>

            <div class="p-4 sm:p-5">
                <RosterListToolbar
                    v-model:query="query"
                    v-model:category="category"
                    v-model:branch="branch"
                    placeholder="Buscar en la plantilla..."
                    :category-options="list.categoryOptions.value"
                    :branch-options="list.branchOptions.value"
                />
            </div>
            <ul class="divide-y divide-border border-t border-border">
                <li v-for="a in list.paged.value" :key="`act-${a.identity_key ?? a.employee_id}`" class="flex items-center justify-between gap-3 px-4 py-2.5 sm:px-5">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">{{ a.employee_name }}</p>
                        <p class="text-xs text-muted-foreground">{{ a.branch_name || 'Sin sucursal asignada' }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusClasses(a.status)" :title="a.reconciliation_note ?? ''">
                        {{ a.status_label }}
                    </span>
                </li>
                <li v-if="!list.paged.value.length" class="px-4 py-6 text-center text-sm text-muted-foreground">Sin coincidencias con los filtros.</li>
            </ul>
            <RosterListPager v-model="page" :total="list.filtered.value.length" :page-size="list.pageSize" :total-pages="list.totalPages.value" />
        </template>
    </AppSectionCard>
</template>
