<script setup lang="ts">
import { AlertTriangle, Search, Users } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { statusClasses } from '@/components/asignaciones/rosterStatus'
import type { LaborRosterSummary, RosterMovementItem } from '@/types/asignaciones'

const props = defineProps<{
    roster: LaborRosterSummary
    active: RosterMovementItem[]
    periodLabel?: string | null
}>()

const query = ref('')

// "Activos confirmados" = en la nómina NOI del periodo y sin conflicto pendiente.
const confirmed = computed(() => props.active.filter((a) => !a.requires_review))
const visible = computed(() => {
    const q = query.value.trim().toLowerCase()

    return q
        ? confirmed.value.filter((a) => a.employee_name.toLowerCase().includes(q) || (a.branch_name ?? '').toLowerCase().includes(q))
        : confirmed.value
})

const variation = computed(() => props.roster.plantilla - props.roster.plantilla_anterior)
</script>

<template>
    <section class="app-card overflow-hidden">
        <div class="border-b px-4 py-4 sm:px-6 sm:py-5">
            <div class="flex items-center gap-2">
                <Users class="size-4 text-indigo-500" />
                <h2 class="text-lg font-bold tracking-tight">Plantilla del periodo</h2>
            </div>
            <p class="mt-1 text-sm text-muted-foreground">
                Personas en la nómina NOI de {{ periodLabel || 'este periodo' }}. Aparecer o no en nómina indica presencia, no vigencia laboral.
            </p>

            <div v-if="roster.calculado" class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-border/70 px-4 py-3">
                    <p class="text-xs text-muted-foreground">Plantilla NOI</p>
                    <p class="text-2xl font-black">{{ roster.plantilla }}</p>
                </div>
                <div class="rounded-2xl border border-border/70 px-4 py-3">
                    <p class="text-xs text-muted-foreground">Periodo anterior{{ roster.periodo_anterior ? ` (${roster.periodo_anterior})` : '' }}</p>
                    <p class="text-2xl font-black">
                        {{ roster.plantilla_anterior }}
                        <span v-if="roster.plantilla_anterior" class="text-sm font-semibold" :class="variation < 0 ? 'text-rose-500' : 'text-emerald-500'">
                            {{ variation > 0 ? '+' : '' }}{{ variation }}
                        </span>
                    </p>
                </div>
                <div class="rounded-2xl border border-border/70 px-4 py-3">
                    <p class="text-xs text-muted-foreground">Activos confirmados</p>
                    <p class="text-2xl font-black">{{ confirmed.length }}</p>
                </div>
                <div class="rounded-2xl border border-border/70 px-4 py-3">
                    <p class="text-xs text-muted-foreground">Ambos NOI / solo normal / solo fiscal</p>
                    <p class="text-lg font-black">{{ roster.fuentes.ambos }} / {{ roster.fuentes.solo_normal }} / {{ roster.fuentes.solo_fiscal }}</p>
                </div>
            </div>

            <p v-if="roster.mass_drop_detected" class="mt-3 flex items-start gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    La plantilla cayó {{ Math.round(roster.drop_ratio * 100) }}% respecto al periodo anterior. Una caída así suele indicar un archivo NOI
                    incompleto, no bajas simultáneas: las ausencias quedan en "Pendientes de conciliación", nunca como bajas confirmadas.
                </span>
            </p>
            <p v-if="roster.sin_clasificar" class="mt-3 flex items-start gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    {{ roster.sin_clasificar }} ausencia(s) vienen de un cálculo anterior a la conciliación y no están clasificadas. Ejecuta "Actualizar BD"
                    del periodo para clasificarlas; mientras tanto no cuentan como bajas confirmadas.
                </span>
            </p>
        </div>

        <div v-if="!roster.calculado" class="px-4 py-10 text-center text-sm text-muted-foreground sm:px-6">
            La plantilla de este periodo aún no se ha calculado: ejecuta "Actualizar BD" en Histórico General.
        </div>

        <div v-else class="p-4 sm:p-6">
            <div class="relative mb-3 w-full sm:w-[280px]">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <input v-model="query" type="text" class="app-input h-10 pl-10" placeholder="Buscar en activos confirmados..." />
            </div>
            <ul class="max-h-[22rem] divide-y divide-border/60 overflow-y-auto rounded-2xl border border-border/70">
                <li v-for="a in visible" :key="`act-${a.employee_id}`" class="flex items-center justify-between gap-3 px-4 py-2.5">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">{{ a.employee_name }}</p>
                        <p class="text-xs text-muted-foreground">{{ a.branch_name || 'Sin sucursal asignada' }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold" :class="statusClasses(a.status)" :title="a.reconciliation_note ?? ''">
                        {{ a.status_label }}
                    </span>
                </li>
                <li v-if="!visible.length" class="px-4 py-6 text-center text-sm text-muted-foreground">Sin coincidencias.</li>
            </ul>
        </div>
    </section>
</template>
