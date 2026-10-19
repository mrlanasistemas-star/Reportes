<script setup lang="ts">
import { nextTick, reactive, ref } from 'vue'
import OkrCompleteKrDialog from '@/components/okr/OkrCompleteKrDialog.vue'
import OkrGoalsTable from '@/components/okr/wizard/OkrGoalsTable.vue'
import OkrKrEditor from '@/components/okr/wizard/OkrKrEditor.vue'
import { newKrRow } from '@/components/okr/wizard/okrWizard'
import type { KrRow } from '@/components/okr/wizard/okrWizard'

const kpis = [
    { id: 1, name: 'Colocación', unit: 'currency', type: 'cumulative', direction: 'increase', automation: 'automatic' },
    { id: 2, name: 'Mora', unit: 'percentage', type: 'percentage', direction: 'decrease', automation: 'manual' },
    { id: 3, name: 'Clientes nuevos', unit: 'count', type: 'cumulative', direction: 'increase', automation: 'manual' },
    { id: 4, name: 'Cartera', unit: 'currency', type: 'balance', direction: 'increase', automation: 'automatic' },
]

const branchScope = reactive({ scope_type: 'branch' as const, branch_id: 2 as number | null, start_date: '2026-10-01' })
const branchRows = ref<KrRow[]>([
    newKrRow({ kpi_id: 3, description: 'Más clientes', weight: '50', baseline_value: '30000' }),
    newKrRow({ kpi_id: 2, description: 'Bajar mora', weight: '50' }),
])

const empScope = reactive({ scope_type: 'employee' as const, branch_id: 2 as number | null, employee_id: 21 as number | null, start_date: '2026-10-01' })
const empMounted = ref(true)
const empRows = ref<KrRow[]>([
    newKrRow({ kpi_id: 1, description: 'Colocar más', weight: '60' }),
    newKrRow({ kpi_id: 2, description: 'Bajar mora', weight: '40' }),
])

async function resetEmployee() {
    empMounted.value = false
    await nextTick()
    empRows.value = [newKrRow({ kpi_id: 1, description: 'Colocar más', weight: '60' }), newKrRow({ kpi_id: 4, description: 'Más cartera', weight: '40' })]
    empMounted.value = true
}

const edRows = ref<KrRow[]>([
    newKrRow({ kpi_id: 1, description: 'A', weight: '50' }),
    newKrRow({ kpi_id: 2, description: 'B', weight: '30' }),
    newKrRow({ kpi_id: 3, description: 'C', weight: '20' }),
])

// "Editar metas" de un OKR individual: lo guardado vuelve como `existing`.
const dialogOpen = ref(false)
const target = ref<any>({
    id: 77, title: 'OKR individual', employee: 'Gestor Prueba', scope_type: 'employee', branch_id: 2, employee_id: 21, start_date: '2026-10-01',
    existing: [{ kpi: kpis[0], description: 'Colocar más', weight: 100, baseline_value: 20000, target_value: 25000, baseline_source: 'manual' }],
})

function reopenFromSaved() {
    const saved = (window as any).__okr.saved()
    const last = saved[saved.length - 1]
    target.value = {
        ...target.value,
        existing: last.key_results.map((kr: any) => ({ ...kr, kpi: kpis.find((k) => k.id === kr.kpi_id), baseline_source: 'manual' })),
    }
    dialogOpen.value = true
}

;(window as any).__state = { branchRows, empRows, edRows, empScope, branchScope }
</script>

<template>
    <div class="space-y-6">
        <section id="branch">
            <h2 class="font-bold">Sucursal</h2>
            <OkrGoalsTable v-model:rows="branchRows" :kpis="kpis" :scope="branchScope" scope-label="de la sucursal" />
        </section>

        <section id="employee">
            <h2 class="font-bold">Gestor</h2>
            <div class="flex flex-wrap gap-2 text-xs">
                <button id="emp-reset" type="button" @click="resetEmployee">reiniciar</button>
                <button id="emp-toggle" type="button" @click="empMounted = !empMounted">montar/desmontar</button>
                <button id="emp-change-employee" type="button" @click="empScope.employee_id = empScope.employee_id === 21 ? 22 : 21">cambiar gestor</button>
                <button id="emp-change-branch" type="button" @click="empScope.branch_id = empScope.branch_id === 2 ? 3 : 2">cambiar sucursal</button>
                <button id="emp-change-date" type="button" @click="empScope.start_date = empScope.start_date === '2026-10-01' ? '2026-11-01' : '2026-10-01'">cambiar fecha</button>
                <button id="emp-change-kpi" type="button" @click="empRows[0].kpi_id = empRows[0].kpi_id === 1 ? 4 : 1">cambiar KPI</button>
            </div>
            <OkrGoalsTable v-if="empMounted" v-model:rows="empRows" :kpis="kpis" :scope="empScope" scope-label="del gestor" />
        </section>

        <section id="editor">
            <h2 class="font-bold">Ponderaciones</h2>
            <OkrKrEditor v-model:rows="edRows" :kpis="kpis" />
        </section>

        <section id="dialog">
            <button id="dialog-open" type="button" @click="dialogOpen = true">abrir editar metas</button>
            <button id="dialog-reopen" type="button" @click="reopenFromSaved">reabrir desde lo guardado</button>
            <OkrCompleteKrDialog v-model:open="dialogOpen" :target="target" :kpis="kpis" />
        </section>
    </div>
</template>
