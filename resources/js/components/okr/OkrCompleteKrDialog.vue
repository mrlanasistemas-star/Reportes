<script setup lang="ts">
// "Configurar Key Results" (15-oct-2026) — completa un OKR en borrador que
// nació sin KR (individuales creados antes de la corrección del asistente),
// sin rehacer la asignación: mismo editor y misma tabla de metas que el
// asistente; la línea base del colaborador se CAPTURA manualmente. Nunca copia
// la meta de la sucursal; los pesos suman 100% dentro de este OKR.
import { router } from '@inertiajs/vue3'
import { ArrowLeft, ArrowRight, CheckCircle2, Loader2, Settings2 } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref, watch } from 'vue'
import OkrGoalsTable from '@/components/okr/wizard/OkrGoalsTable.vue'
import OkrKrEditor from '@/components/okr/wizard/OkrKrEditor.vue'
import { isAutomatic, krGoalIsComplete, krStructureIsComplete, newKrRow, serializeKr } from '@/components/okr/wizard/okrWizard'
import type { KrRow } from '@/components/okr/wizard/okrWizard'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { useOkrBaselinePreview } from '@/composables/useOkrBaselinePreview'
import { okrRequest } from '@/lib/okrHttp'

const props = defineProps<{
    target: { id: number; title: string; employee: string | null; scope_type: string; branch_id: number | null; employee_id: number | null; start_date: string; existing?: any[] } | null
    kpis: any[]
}>()
const open = defineModel<boolean>('open', { default: false })

const step = ref(1)
const rows = ref<KrRow[]>([])
const saving = ref(false)
const { consult } = useOkrBaselinePreview()

watch(open, (v) => {
    if (v) {
        step.value = 1
        // "Editar metas": precarga los KR del borrador (con su base y meta) para corregirlos.
        rows.value = props.target?.existing?.length
            ? props.target.existing.map((kr: any) => newKrRow({
                kpi_id: kr.kpi.id, description: kr.description, weight: String(kr.weight),
                baseline_value: kr.baseline_value === null ? '' : String(kr.baseline_value), target_value: String(kr.target_value),
                increment: kr.baseline_value === null ? '' : String(Number(kr.target_value) - Number(kr.baseline_value)),
                baseline_mode: kr.baseline_source === 'manual_authorized' ? 'manual_authorized' : (props.target?.scope_type !== 'employee' && kr.kpi.automation === 'automatic' ? 'auto' : 'manual'),
                baseline_manual_reason: kr.baseline_source === 'manual_authorized' ? 'Línea base manual autorizada previamente (ver bitácora).' : '',
            }))
            : [newKrRow({ weight: '100' })]
    }
})

function kpiOf(id: number | null) {
    return props.kpis.find((k) => k.id === id) ?? null
}

const scope = computed(() => ({
    scope_type: (props.target?.scope_type === 'employee' ? 'employee' : 'branch') as 'employee' | 'branch',
    branch_id: props.target?.branch_id ?? null, employee_id: props.target?.employee_id ?? null, start_date: props.target?.start_date ?? '',
}))

function toGoals() {
    step.value = 2

    // Sucursal: consulta aquí su base congelada en Reportería. Gestor (18-oct-2026):
    // la base individual se SUGIERE sola desde OkrGoalsTable al montarse (precargada
    // y editable), por eso no se dispara aquí.
    if (scope.value.scope_type === 'branch') {
        rows.value.filter((kr) => isAutomatic(kpiOf(kr.kpi_id)) && !kr.preview && !kr.loading).forEach((kr) => consult(kr, scope.value))
    }
}

const complete = computed(() => rows.value.length > 0 && rows.value.every((kr) => krGoalIsComplete(kr, kpiOf(kr.kpi_id), scope.value.scope_type)))

async function save() {
    if (!props.target) {
        return
    }

    saving.value = true
    const res = await okrRequest(`/okr/${props.target.id}/key-results/complete`, {
        method: 'POST', json: { key_results: rows.value.map((kr) => serializeKr(kr, kpiOf(kr.kpi_id), scope.value.scope_type)), replace: !!props.target.existing?.length },
    })
    saving.value = false

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se pudieron guardar los Key Results', text: res.message })

        return
    }

    open.value = false
    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Key Results guardados — ya puede activarse', timer: 2800, showConfirmButton: false })
    router.reload()
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] w-[96vw] max-w-[96vw] overflow-y-auto sm:max-w-5xl">
            <DialogTitle class="flex items-center gap-2 text-base font-bold"><Settings2 class="size-5 text-primary" /> {{ target?.existing?.length ? 'Editar metas' : 'Configurar Key Results' }}</DialogTitle>
            <p class="-mt-1 text-xs text-muted-foreground">
                {{ target?.employee ? `OKR individual de ${target.employee}` : target?.title }} — paso {{ step }} de 2: {{ step === 1 ? 'KPI y pesos' : 'línea base y meta individuales' }}.
                La meta de la sucursal no se copia.
            </p>

            <OkrKrEditor v-if="step === 1" v-model:rows="rows" :kpis="kpis" placeholder="Incrementar mi colocación de cartera" />
            <OkrGoalsTable v-else v-model:rows="rows" :kpis="kpis" :scope="scope" :scope-label="target?.employee_id ? 'del colaborador' : 'de la sucursal'" />

            <div class="flex items-center justify-between gap-2 border-t border-border pt-3">
                <Button v-if="step === 2" variant="outline" class="gap-1.5 rounded-xl" :disabled="saving" @click="step = 1"><ArrowLeft class="size-4" /> KPI y pesos</Button>
                <Button v-else variant="ghost" class="rounded-xl" @click="open = false">Cancelar</Button>
                <Button v-if="step === 1" class="gap-1.5 rounded-xl" :disabled="!krStructureIsComplete(rows)" @click="toGoals">Siguiente <ArrowRight class="size-4" /></Button>
                <Button v-else class="gap-1.5 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500" :disabled="!complete || saving" @click="save">
                    <Loader2 v-if="saving" class="size-4 animate-spin" /><CheckCircle2 v-else class="size-4" /> {{ saving ? 'Guardando…' : 'Guardar Key Results' }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
