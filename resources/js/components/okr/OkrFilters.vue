<script setup lang="ts">
// Barra de filtros — reproduce docs/imagenesOKR/1.png: UNA fila (Sucursal,
// Colaborador, Estatus, Periodo, Buscar, Limpiar filtros). Los filtros
// adicionales exigidos por el PDF/backend (KPI, Responsable, fechas) viven
// detrás de "Más filtros" — nunca rompen esta composición.
import { onMounted, ref, watch } from 'vue'
import { ChevronDown, Search, SlidersHorizontal, X } from 'lucide-vue-next'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import SelectField from '@/components/forms/SelectField.vue'
import DatePickerField from '@/components/forms/DatePickerField.vue'
import { Button } from '@/components/ui/button'

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
        employeeOptions.value = (await res.json()).employees ?? []
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

const showMore = ref(false)
</script>

<template>
    <div class="app-card p-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
            <div class="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <SelectField
                    v-model="model.branch_id"
                    label="Sucursal"
                    placeholder="Todas las sucursales"
                    :options="[{ value: '', label: 'Todas las sucursales' }, ...branches.map((b) => ({ value: String(b.id), label: b.name! }))]"
                    @update:model-value="apply"
                />
                <SearchableSelect
                    v-model="model.employee_id"
                    label="Colaborador"
                    placeholder="Todos los colaboradores"
                    search-placeholder="Buscar colaborador..."
                    :options="employeeOptions"
                    label-key="full_name"
                    secondary-key="__none"
                    allow-null
                    null-label="Todos los colaboradores"
                    @update:search="onEmployeeSearch"
                    @change="apply"
                />
                <SelectField
                    v-model="model.status"
                    label="Estatus"
                    placeholder="Todos los estatus"
                    :options="[{ value: '', label: 'Todos los estatus' }, ...statuses.map((s) => ({ value: s, label: statusLabel[s] ?? s }))]"
                    @update:model-value="apply"
                />
                <SelectField
                    v-model="model.period_id"
                    label="Periodo"
                    placeholder="Periodo actual"
                    :options="[{ value: '', label: 'Periodo actual' }, ...periods.map((p) => ({ value: String(p.id), label: p.label }))]"
                    @update:model-value="apply"
                />
                <div class="w-full space-y-1.5 sm:col-span-3 lg:col-span-1">
                    <label class="text-sm font-semibold text-foreground">Buscar</label>
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input v-model="model.search" placeholder="Objetivo, colaborador o KPI..." class="app-input pl-9" @keyup.enter="apply">
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 gap-2">
                <Button type="button" variant="outline" size="sm" class="h-11 gap-1.5 rounded-xl" @click="showMore = !showMore">
                    <SlidersHorizontal class="size-3.5" /> Más filtros <ChevronDown class="size-3.5 transition-transform" :class="showMore ? 'rotate-180' : ''" />
                </Button>
                <Button type="button" variant="ghost" size="sm" class="h-11 gap-1.5 rounded-xl text-muted-foreground" @click="clear">
                    <X class="size-3.5" /> Limpiar filtros
                </Button>
            </div>
        </div>

        <div v-if="showMore" class="mt-3 grid grid-cols-2 gap-3 border-t border-border pt-3 sm:grid-cols-4">
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
            <DatePickerField v-model="model.start_date" label="Fecha inicio" clearable @update:model-value="apply" />
            <DatePickerField v-model="model.end_date" label="Fecha término" clearable @update:model-value="apply" />
        </div>
    </div>
</template>
