<script setup lang="ts">
// Paso "Metas y KR por gestor" (15-oct-2026): UN gestor con SUS propios Key
// Results, SU línea base (consultada para ESE gestor), SU incremento y SU
// meta. Copiar la estructura del KR general es opcional y visible, y nunca
// copia importes ni líneas base.
import { AlertTriangle, CheckCircle2, ChevronDown, Copy, Loader2 } from 'lucide-vue-next'
import { computed } from 'vue'
import { Button } from '@/components/ui/button'
import OkrGoalsTable from './OkrGoalsTable.vue'
import OkrKrEditor from './OkrKrEditor.vue'
import { avatarTone, initialsOf, krGoalIsComplete, krStructureIsComplete, totalWeight } from './okrWizard'
import type { GestorPlan } from './okrWizard'

const gestor = defineModel<GestorPlan>("gestor", { required: true })
const props = defineProps<{
    index: number
    kpis: any[]
    branchName: string | null
    scope: { branch_id: number | null; start_date: string }
    canCopy: boolean
    open: boolean
}>()
const emit = defineEmits<{ copy: []; toggle: [] }>()

function kpiOf(id: number | null) {
    return props.kpis.find((k) => k.id === id) ?? null
}

const structureOk = computed(() => krStructureIsComplete(gestor.value.key_results))
const goalsOk = computed(() => structureOk.value && gestor.value.key_results.every((kr) => krGoalIsComplete(kr, kpiOf(kr.kpi_id))))
const loading = computed(() => gestor.value.key_results.some((kr) => kr.loading))
const missing = computed(() => {
    if (!gestor.value.key_results.length) {
        return 'Sin Key Results'
    }

    if (!structureOk.value) {
        return `KPI/descripción o pesos incompletos (${totalWeight(gestor.value.key_results)}%)`
    }

    return goalsOk.value ? null : 'Falta línea base o meta'
})
</script>

<template>
    <article class="overflow-hidden rounded-2xl border bg-card shadow-sm transition duration-300 hover:shadow-md" :class="goalsOk ? 'border-emerald-500/30' : 'border-border'">
        <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-muted/30" @click="emit('toggle')">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-xs font-bold text-white shadow" :class="avatarTone(gestor.employee_id)">{{ initialsOf(gestor.employee_name) }}</span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold text-foreground">Gestor {{ index + 1 }} — {{ gestor.employee_name }}</p>
                <p class="truncate text-[11px] text-muted-foreground">{{ gestor.position ?? 'Puesto no registrado' }} · {{ branchName }}</p>
            </div>
            <Loader2 v-if="loading" class="size-4 animate-spin text-primary" />
            <span v-if="goalsOk" class="flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-[10px] font-bold text-emerald-700 dark:text-emerald-300"><CheckCircle2 class="size-3.5" /> Completo</span>
            <span v-else class="flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-[10px] font-bold text-amber-700 dark:text-amber-300"><AlertTriangle class="size-3.5" /> {{ missing }}</span>
            <ChevronDown class="size-4 text-muted-foreground transition" :class="open ? 'rotate-180' : ''" />
        </button>

        <div v-if="open" class="space-y-4 border-t border-border p-4 animate-in fade-in duration-200">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">1 · Key Results de {{ gestor.employee_name }}</p>
                    <p class="text-[11px] text-muted-foreground">Sus pesos suman 100% dentro de SU OKR. Puede tener KR distintos a los de la sucursal.</p>
                </div>
                <Button type="button" variant="outline" size="sm" class="gap-1.5 rounded-xl" :disabled="!canCopy" @click="emit('copy')">
                    <Copy class="size-3.5" /> Copiar estructura del KR general
                </Button>
            </div>
            <OkrKrEditor v-model:rows="gestor.key_results" :kpis="kpis" placeholder="Incrementar mi colocación de cartera" />

            <div v-if="structureOk" class="space-y-2">
                <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">2 · Línea base, incremento y meta individuales</p>
                <OkrGoalsTable v-model:rows="gestor.key_results" :kpis="kpis" :scope="{ ...scope, scope_type: 'employee', employee_id: gestor.employee_id }" scope-label="del gestor" />
            </div>
            <p v-else class="rounded-xl border border-dashed border-border p-3 text-center text-xs text-muted-foreground">Completa sus Key Results (KPI, descripción y pesos al 100%) para capturar su línea base y su meta.</p>
        </div>
    </article>
</template>
