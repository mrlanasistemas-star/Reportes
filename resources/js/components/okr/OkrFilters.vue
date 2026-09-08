<script setup lang="ts">
// Panel de filtros del dashboard (sección O del pedido) — combinables, TODOS
// aplicados en backend (DashboardController::applyDashboardFilters()), nunca
// solo en Vue. El buscador de colaborador es asíncrono (GET /okr/employees-lookup)
// para no cargar cientos de registros de una sola vez (sección AP).
import { onMounted, ref, watch } from 'vue'
import { SlidersHorizontal, X } from 'lucide-vue-next'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import SelectField from '@/components/forms/SelectField.vue'
import TextField from '@/components/forms/TextField.vue'
import DatePickerField from '@/components/forms/DatePickerField.vue'
import { Button } from '@/components/ui/button'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'

type Option = { id: number; name?: string; full_name?: string }
type FilterState = {
    branch_id: string
    employee_id: string
    status: string
    search: string
    period_id: string
    start_date: string | null
    end_date: string | null
    kpi_id: string
    responsible_user_id: string
}

defineProps<{
    branches: Option[]
    statuses: string[]
    kpis: Option[]
    responsibles: Option[]
    periods: { id: number; label: string }[]
}>()

const emit = defineEmits<{ (e: 'apply'): void; (e: 'clear'): void }>()

const model = defineModel<FilterState>({ required: true })

const statusLabel: Record<string, string> = { draft: 'Borrador', active: 'Activo', closed: 'Cerrado', cancelled: 'Cancelado' }

const employeeOptions = ref<{ id: number; full_name: string }[]>([])
let employeeSearchTimer: ReturnType<typeof setTimeout> | null = null

async function loadEmployees(search = '') {
    const params = new URLSearchParams()
    if (model.value.branch_id) params.set('branch_id', model.value.branch_id)
    if (search) params.set('search', search)
    try {
        const res = await fetch(`/okr/employees-lookup?${params.toString()}`, { headers: { Accept: 'application/json' } })
        if (!res.ok) return
        const data = await res.json()
        employeeOptions.value = data.employees ?? []
    } catch {
        // Búsqueda opcional — un fallo de red no debe romper el resto del dashboard.
    }
}

function onEmployeeSearch(term: string) {
    if (employeeSearchTimer) clearTimeout(employeeSearchTimer)
    employeeSearchTimer = setTimeout(() => loadEmployees(term), 250)
}

onMounted(() => loadEmployees())
watch(() => model.value.branch_id, () => { model.value.employee_id = ''; loadEmployees() })

function apply() { emit('apply') }
function clear() { emit('clear') }
</script>

<template>
    <div class="app-card space-y-4 p-5">
        <div class="flex items-center gap-2">
            <SlidersHorizontal class="size-4 text-primary" />
            <h2 class="text-sm font-bold uppercase tracking-wide text-foreground">Filtros</h2>
            <OkrHelpTooltip text="Combina cualquier filtro: sucursal, colaborador, estado, KPI, responsable y rango de fechas. Las cards de arriba y la tabla siempre reflejan exactamente el mismo alcance filtrado." />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <SelectField
                v-model="model.branch_id"
                label="Sucursal"
                placeholder="Todas"
                :options="[{ value: '', label: 'Todas' }, ...branches.map((b) => ({ value: String(b.id), label: b.name! }))]"
                @update:model-value="apply"
            />

            <SearchableSelect
                v-model="model.employee_id"
                label="Colaborador"
                placeholder="Todos"
                search-placeholder="Buscar colaborador..."
                :options="employeeOptions"
                label-key="full_name"
                secondary-key="__none"
                allow-null
                null-label="Todos"
                @update:search="onEmployeeSearch"
                @change="apply"
            />

            <SelectField
                v-model="model.status"
                label="Estado"
                placeholder="Todos"
                :options="[{ value: '', label: 'Todos' }, ...statuses.map((s) => ({ value: s, label: statusLabel[s] ?? s }))]"
                @update:model-value="apply"
            />

            <SelectField
                v-model="model.kpi_id"
                label="KPI"
                placeholder="Todos"
                :options="[{ value: '', label: 'Todos' }, ...kpis.map((k) => ({ value: String(k.id), label: k.name! }))]"
                @update:model-value="apply"
            />

            <SelectField
                v-model="model.responsible_user_id"
                label="Responsable"
                placeholder="Todos"
                :options="[{ value: '', label: 'Todos' }, ...responsibles.map((u) => ({ value: String(u.id), label: u.name! }))]"
                @update:model-value="apply"
            />

            <SelectField
                v-model="model.period_id"
                label="Periodo"
                placeholder="Cualquiera"
                :options="[{ value: '', label: 'Cualquiera' }, ...periods.map((p) => ({ value: String(p.id), label: p.label }))]"
                @update:model-value="apply"
            />

            <DatePickerField v-model="model.start_date" label="Fecha inicio" clearable @update:model-value="apply" />
            <DatePickerField v-model="model.end_date" label="Fecha término" clearable @update:model-value="apply" />

            <TextField
                v-model="model.search"
                label="Buscar objetivo"
                placeholder="Ej. colocación, EBITDA…"
                class="xl:col-span-2"
                @keyup.enter="apply"
            />
        </div>

        <div class="flex justify-end border-t border-border pt-3">
            <Button type="button" variant="ghost" size="sm" class="gap-1.5 text-muted-foreground" @click="clear">
                <X class="size-3.5" /> Limpiar filtros
            </Button>
        </div>
    </div>
</template>
