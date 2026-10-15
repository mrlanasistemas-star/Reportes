<script setup lang="ts">
// Filtros de seguimiento (15-oct-2026): sucursal → solo sus OKR; colaborador →
// los OKR en los que REALMENTE participa (incluye excolaboradores, marcados
// "(baja)", porque es consulta histórica — nunca asignación). Siempre se indica
// si se está viendo la sucursal completa o un gestor individual.
import { router } from '@inertiajs/vue3'
import { Building2, Eye, UserRound, X } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import SelectField from '@/components/forms/SelectField.vue'
import { Button } from '@/components/ui/button'
import { okrRequest } from '@/lib/okrHttp'

const props = defineProps<{ objective: any; branches: { id: number; name: string }[]; periods: { id: number; label: string }[] }>()

const branchId = ref<number | null>(props.objective.branch?.id ?? null)
const employeeId = ref<number | null>(null)
const periodId = ref<string>('')
const objectiveId = ref<number>(props.objective.id)
const employees = ref<{ id: number; full_name: string }[]>([])
const objectives = ref<{ id: number; title: string; label?: string }[]>([{ id: props.objective.id, title: props.objective.title, label: props.objective.title }])
const loadingObjectives = ref(false)
let objectiveSearch = ''
let timer: ReturnType<typeof setTimeout> | null = null
let ev = 0
let ov = 0

async function loadEmployees() {
    const mine = ++ev
    const params = new URLSearchParams({ mode: 'history' })

    if (branchId.value) {
        params.set('branch_id', String(branchId.value))
    }

    const res = await okrRequest(`/okr/employees-lookup?${params.toString()}`)

    if (mine === ev) {
        employees.value = res.ok ? res.data.employees ?? [] : []
    }
}

async function loadObjectives() {
    const mine = ++ov
    const params = new URLSearchParams()

    if (branchId.value) {
        params.set('branch_id', String(branchId.value))
    }

    if (employeeId.value) {
        params.set('employee_id', String(employeeId.value))
    }

    if (periodId.value) {
        params.set('period_id', periodId.value)
    }

    if (objectiveSearch) {
        params.set('search', objectiveSearch)
    }

    loadingObjectives.value = true
    const res = await okrRequest(`/okr/objectives-lookup?${params.toString()}`)

    if (mine === ov) {
        loadingObjectives.value = false
        objectives.value = res.ok ? res.data.objectives ?? [] : []
    }
}

onMounted(() => {
    loadEmployees()
    loadObjectives()
})
watch(branchId, () => {
    employeeId.value = null
    loadEmployees()
    loadObjectives()
})
watch([employeeId, periodId], loadObjectives)

function onSearch(term: string) {
    objectiveSearch = term

    if (timer) {
        clearTimeout(timer)
    }

    timer = setTimeout(loadObjectives, 250)
}

function go(id: number | string | null) {
    if (id && Number(id) !== props.objective.id) {
        router.get(`/okr/${id}`)
    }
}

function clear() {
    branchId.value = null
    employeeId.value = null
    periodId.value = ''
    objectiveSearch = ''
    loadEmployees()
    loadObjectives()
}
</script>

<template>
    <section class="rounded-2xl border border-border bg-card p-3 shadow-sm">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
            <div class="grid flex-1 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <SelectField v-model="branchId as any" label="Sucursal" placeholder="Todas" :options="[{ value: '', label: 'Todas' }, ...branches.map((b) => ({ value: b.id, label: b.name }))]" />
                <SearchableSelect v-model="employeeId as any" label="Colaborador (participantes)" placeholder="Todos" :options="employees" label-key="full_name" secondary-key="__none" allow-null null-label="Todos" />
                <SearchableSelect v-model="objectiveId as any" label="Objective / OKR" :options="objectives" label-key="label" secondary-key="__none" @update:search="onSearch" @change="go" />
                <SelectField v-model="periodId" label="Periodo" placeholder="Todos" :options="[{ value: '', label: 'Todos' }, ...periods.map((p) => ({ value: String(p.id), label: p.label }))]" />
            </div>
            <Button type="button" variant="ghost" size="sm" class="h-11 shrink-0 gap-1.5 rounded-xl text-muted-foreground" @click="clear"><X class="size-3.5" /> Limpiar</Button>
        </div>
        <div class="mt-2.5 flex flex-wrap items-center gap-2 rounded-xl bg-muted/40 px-3 py-2 text-xs">
            <Eye class="size-3.5 text-muted-foreground" />
            <span class="text-muted-foreground">Estás viendo:</span>
            <span v-if="objective.scope_type === 'branch'" class="flex items-center gap-1 font-bold text-foreground"><Building2 class="size-3.5 text-primary" /> Sucursal completa — {{ objective.branch?.name }}</span>
            <span v-else class="flex items-center gap-1 font-bold text-foreground"><UserRound class="size-3.5 text-primary" /> Gestor individual — {{ objective.employee?.full_name }}</span>
            <span v-if="employeeId && objectives.length" class="text-muted-foreground">· {{ objectives.length }} OKR donde participa el colaborador elegido (elige uno para abrirlo)</span>
            <span v-if="loadingObjectives" class="text-muted-foreground">· buscando…</span>
        </div>
    </section>
</template>
