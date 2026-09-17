<script setup lang="ts">
import { computed } from 'vue'
import { ArrowLeftRight } from 'lucide-vue-next'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'

type PeriodOption = { id: number; label: string; code: string; type: string; has_snapshot: boolean }
type BranchOption = { id: number; name: string }
type EmployeeOption = { id: number; name: string }

const props = defineProps<{
    periods: PeriodOption[]
    branches: BranchOption[]
    employees: EmployeeOption[]
    employeesLoading: boolean
}>()

const periodA = defineModel<number | null>('periodA', { required: true })
const periodB = defineModel<number | null>('periodB', { required: true })
const scope = defineModel<'general' | 'branch' | 'employee'>('scope', { required: true })
const branchId = defineModel<number | null>('branchId', { required: true })
const employeeId = defineModel<number | null>('employeeId', { required: true })

const emit = defineEmits<{ swap: [] }>()

// Periodo B solo puede ser del MISMO tipo que Periodo A (B6) — mensual con mensual,
// bimestral con bimestral, trimestral con trimestral. Nunca mezclado.
const periodAType = computed(() => props.periods.find(p => p.id === periodA.value)?.type ?? null)

const periodAOptions = computed(() => props.periods.filter(p => p.has_snapshot))
const periodBOptions = computed(() => {
    if (!periodAType.value) return props.periods.filter(p => p.has_snapshot)
    return props.periods.filter(p => p.has_snapshot && p.type === periodAType.value && p.id !== periodA.value)
})

const branchOptions = computed(() => props.branches)
const employeeOptions = computed(() => props.employees)
</script>

<template>
    <div class="rounded-2xl border bg-white p-5 shadow-sm">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_auto_1fr]">
            <SearchableSelect v-model="periodA" :options="periodAOptions" label="Periodo A" label-key="label" secondary-key="code"
                               placeholder="Selecciona el periodo actual" />
            <div class="flex items-end justify-center pb-2 lg:pb-0 lg:items-center">
                <button type="button" @click="emit('swap')"
                        title="Intercambiar periodos"
                        class="flex size-10 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 shadow-sm transition hover:border-indigo-300 hover:text-indigo-600">
                    <ArrowLeftRight class="size-4" />
                </button>
            </div>
            <SearchableSelect v-model="periodB" :options="periodBOptions" label="Periodo B (comparado)" label-key="label" secondary-key="code"
                               placeholder="Selecciona el periodo a comparar" />
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-foreground">Alcance</label>
                <div class="flex h-11 items-center gap-1 rounded-2xl border border-slate-200 bg-slate-50 p-1">
                    <button v-for="opt in [{ v: 'general', l: 'General' }, { v: 'branch', l: 'Sucursal' }, { v: 'employee', l: 'Gestor' }]" :key="opt.v"
                            type="button" @click="scope = opt.v as any"
                            class="h-full flex-1 rounded-xl text-xs font-bold transition"
                            :class="scope === opt.v ? 'bg-white text-indigo-600 shadow-sm' : 'text-slate-500 hover:text-slate-700'">
                        {{ opt.l }}
                    </button>
                </div>
            </div>
            <SearchableSelect v-if="scope === 'branch'" v-model="branchId" :options="branchOptions" label="Sucursal"
                               label-key="name" placeholder="Selecciona una sucursal" />
            <SearchableSelect v-if="scope === 'employee'" v-model="employeeId" :options="employeeOptions" label="Colaborador"
                               label-key="name" :placeholder="employeesLoading ? 'Cargando colaboradores…' : 'Selecciona un colaborador'"
                               :disabled="employeesLoading" />
        </div>
    </div>
</template>
