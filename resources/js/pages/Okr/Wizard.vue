<script setup lang="ts">
import { computed, ref } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import { ArrowLeft, ArrowRight, Plus, Trash2, CheckCircle2 } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    branches: { id: number; name: string }[]
    employees: { id: number; full_name: string }[]
    users: { id: number; name: string }[]
    kpis: any[]
    objectives: { id: number; title: string; branch_id: number | null }[]
}>()

const step = ref(1)
const steps = ['Contexto', 'Objective', 'Key Results', 'Revisión y activación']

type KrRow = { kpi_id: number | null; description: string; baseline_value: string; target_value: string; weight: string }

const form = useForm({
    scope_type: 'branch' as 'branch' | 'employee',
    branch_id: null as number | null,
    employee_id: null as number | null,
    parent_id: null as number | null,
    title: '',
    responsible_user_id: null as number | null,
    start_date: new Date().toISOString().slice(0, 10),
    duration_weeks: 8,
    key_results: [{ kpi_id: null, description: '', baseline_value: '', target_value: '', weight: '' }] as KrRow[],
})

function addKr() {
    form.key_results.push({ kpi_id: null, description: '', baseline_value: '', target_value: '', weight: '' })
}
function removeKr(i: number) {
    if (form.key_results.length > 1) form.key_results.splice(i, 1)
}

function kpiOf(id: number | null) {
    return props.kpis.find(k => k.id === id)
}

const totalWeight = computed(() => form.key_results.reduce((sum, kr) => sum + (Number(kr.weight) || 0), 0))
const weightValid = computed(() => Math.abs(totalWeight.value - 100) < 0.01)

const branchObjectives = computed(() => props.objectives)

function canAdvance(): boolean {
    if (step.value === 1) {
        return form.scope_type === 'branch' ? !!form.branch_id : !!form.employee_id
    }
    if (step.value === 2) {
        return form.title.trim().length >= 10
    }
    if (step.value === 3) {
        return form.key_results.every(kr => kr.kpi_id && kr.description && kr.target_value !== '') && weightValid.value
    }
    return true
}

function next() { if (canAdvance() && step.value < 4) step.value++ }
function back() { if (step.value > 1) step.value-- }

function submit() {
    form.transform(data => ({
        ...data,
        key_results: data.key_results.map(kr => ({
            ...kr,
            baseline_value: kr.baseline_value === '' ? null : Number(kr.baseline_value),
            target_value: Number(kr.target_value),
            weight: Number(kr.weight),
        })),
    })).post('/okr')
}
</script>

<template>
    <div class="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
        <div class="flex items-center gap-3">
            <Link href="/okr" class="text-slate-400 hover:text-slate-700"><ArrowLeft class="size-5" /></Link>
            <h1 class="text-xl font-black text-slate-950">Asignar OKR</h1>
        </div>

        <!-- Pasos -->
        <div class="flex items-center gap-1 overflow-x-auto pb-1">
            <template v-for="(label, i) in steps" :key="label">
                <div class="flex items-center gap-2 shrink-0" :class="i + 1 <= step ? 'text-indigo-700' : 'text-slate-300'">
                    <span class="flex size-7 items-center justify-center rounded-full text-xs font-black" :class="i + 1 <= step ? 'bg-indigo-700 text-white' : 'bg-slate-100'">{{ i + 1 }}</span>
                    <span class="text-xs font-bold whitespace-nowrap">{{ label }}</span>
                </div>
                <div v-if="i < steps.length - 1" class="h-px w-8 shrink-0" :class="i + 1 < step ? 'bg-indigo-700' : 'bg-slate-200'" />
            </template>
        </div>

        <div v-if="Object.keys(form.errors).length" class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-bold text-rose-700">
            <p v-for="(err, key) in form.errors" :key="key">{{ err }}</p>
        </div>

        <!-- PASO 1: Contexto -->
        <div v-if="step === 1" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-bold text-slate-700">¿A qué nivel aplica este OKR?</p>
            <div class="flex gap-3">
                <button type="button" @click="form.scope_type = 'branch'; form.employee_id = null"
                        class="flex-1 rounded-xl border-2 px-4 py-3 text-sm font-bold transition" :class="form.scope_type === 'branch' ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-500'">
                    Sucursal
                </button>
                <button type="button" @click="form.scope_type = 'employee'; form.branch_id = null"
                        class="flex-1 rounded-xl border-2 px-4 py-3 text-sm font-bold transition" :class="form.scope_type === 'employee' ? 'border-indigo-600 bg-indigo-50 text-indigo-700' : 'border-slate-200 text-slate-500'">
                    Colaborador / Gestor
                </button>
            </div>

            <div v-if="form.scope_type === 'branch'">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Sucursal</label>
                <select v-model="form.branch_id" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm">
                    <option :value="null">— Seleccionar —</option>
                    <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>
            <div v-else class="space-y-3">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Colaborador</label>
                    <select v-model="form.employee_id" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm">
                        <option :value="null">— Seleccionar —</option>
                        <option v-for="e in employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Objective de sucursal al que contribuye (opcional)</label>
                    <select v-model="form.parent_id" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm">
                        <option :value="null">— Ninguno —</option>
                        <option v-for="o in branchObjectives" :key="o.id" :value="o.id">{{ o.title }}</option>
                    </select>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Responsable</label>
                    <select v-model="form.responsible_user_id" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm">
                        <option :value="null">— Yo mismo —</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Fecha de inicio</label>
                    <input v-model="form.start_date" type="date" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" />
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Plazo (semanas)</label>
                <input v-model.number="form.duration_weeks" type="number" min="1" max="104" class="h-11 w-40 rounded-xl border border-slate-200 px-3 text-sm" />
            </div>
        </div>

        <!-- PASO 2: Objective -->
        <div v-if="step === 2" class="space-y-3 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Objective (cualitativo — qué se quiere lograr, no una cifra)</label>
            <textarea v-model="form.title" rows="3" placeholder="Ej. Aumentar la colocación de préstamos activos"
                      class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
            <p class="text-xs text-slate-400">Mínimo 10 caracteres — describe la meta, no un número.</p>
        </div>

        <!-- PASO 3: Key Results -->
        <div v-if="step === 3" class="space-y-4">
            <div v-for="(kr, i) in form.key_results" :key="i" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-black uppercase tracking-wider text-indigo-700">Key Result #{{ i + 1 }}</p>
                    <button v-if="form.key_results.length > 1" type="button" @click="removeKr(i)" class="text-rose-500 hover:text-rose-700"><Trash2 class="size-4" /></button>
                </div>
                <input v-model="kr.description" placeholder="Ej. Incrementar EBITDA de $630k a $750k" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">KPI</label>
                        <select v-model.number="kr.kpi_id" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm">
                            <option :value="null">— Seleccionar —</option>
                            <option v-for="k in kpis" :key="k.id" :value="k.id">{{ k.name }} ({{ k.automation === 'automatic' ? 'automático' : 'manual' }})</option>
                        </select>
                    </div>
                    <div v-if="kpiOf(kr.kpi_id)" class="flex items-end gap-2 text-xs">
                        <span class="rounded-full bg-slate-100 px-2 py-1 font-bold text-slate-600">{{ kpiOf(kr.kpi_id).type }}</span>
                        <span class="rounded-full px-2 py-1 font-bold" :class="kpiOf(kr.kpi_id).direction === 'increase' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                            {{ kpiOf(kr.kpi_id).direction === 'increase' ? 'Incrementar' : 'Disminuir' }}
                        </span>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">Línea base (opcional — se autocompleta al activar si el KPI es automático)</label>
                        <input v-model="kr.baseline_value" type="number" step="0.01" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">Meta</label>
                        <input v-model="kr.target_value" type="number" step="0.01" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">Peso (%)</label>
                        <input v-model="kr.weight" type="number" step="0.01" min="0.01" max="100" class="h-10 w-full rounded-xl border border-slate-200 px-3 text-sm" />
                    </div>
                </div>
            </div>

            <button type="button" @click="addKr" class="inline-flex items-center gap-2 rounded-xl border border-dashed border-indigo-300 px-4 py-2 text-sm font-bold text-indigo-700 hover:bg-indigo-50">
                <Plus class="size-4" /> Agregar Key Result
            </button>

            <div class="rounded-2xl border p-4 shadow-sm" :class="weightValid ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'">
                <p class="text-sm font-black" :class="weightValid ? 'text-emerald-700' : 'text-amber-700'">
                    Resumen del OKR — {{ totalWeight.toFixed(2) }}% ponderado
                    <span v-if="!weightValid"> ({{ totalWeight < 100 ? (100 - totalWeight).toFixed(2) + '% pendiente' : (totalWeight - 100).toFixed(2) + '% excedido' }})</span>
                </p>
                <p v-if="!weightValid" class="mt-1 text-xs text-amber-700">La ponderación de los Key Results debe sumar exactamente 100% para poder activar el OKR.</p>
            </div>
        </div>

        <!-- PASO 4: Revisión -->
        <div v-if="step === 4" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Objective</p>
                <p class="text-sm font-bold text-slate-900">{{ form.title }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 text-sm">
                <p><span class="font-bold text-slate-500">Alcance:</span> {{ form.scope_type === 'branch' ? branches.find(b => b.id === form.branch_id)?.name : employees.find(e => e.id === form.employee_id)?.full_name }}</p>
                <p><span class="font-bold text-slate-500">Plazo:</span> {{ form.duration_weeks }} semanas desde {{ form.start_date }}</p>
            </div>
            <div class="space-y-2">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Key Results ({{ totalWeight.toFixed(2) }}% ponderado)</p>
                <div v-for="(kr, i) in form.key_results" :key="i" class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs">
                    <p class="font-bold text-slate-800">{{ kr.description }}</p>
                    <p class="text-slate-500">{{ kpiOf(kr.kpi_id)?.name }} — base {{ kr.baseline_value || '(auto)' }} → meta {{ kr.target_value }} — peso {{ kr.weight }}%</p>
                </div>
            </div>
            <p class="text-xs text-slate-400">Al crear el OKR queda en BORRADOR. Desde el detalle podrás revisar la línea base y activarlo — al activar, la línea base queda congelada.</p>
        </div>

        <!-- Navegación -->
        <div class="flex justify-between">
            <button type="button" @click="back" :disabled="step === 1" class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 px-4 text-sm font-bold text-slate-600 disabled:opacity-40">
                <ArrowLeft class="size-4" /> Atrás
            </button>
            <button v-if="step < 4" type="button" @click="next" :disabled="!canAdvance()"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-indigo-700 px-4 text-sm font-black text-white disabled:opacity-40">
                Siguiente <ArrowRight class="size-4" />
            </button>
            <button v-else type="button" @click="submit" :disabled="form.processing"
                    class="inline-flex h-10 items-center gap-2 rounded-xl bg-emerald-600 px-5 text-sm font-black text-white disabled:opacity-40">
                <CheckCircle2 class="size-4" /> Crear OKR (borrador)
            </button>
        </div>
    </div>
</template>
