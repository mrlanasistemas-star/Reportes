<script setup lang="ts">
// Módulo OKR — Wizard de creación (rediseño 08-sep-2026, secciones S-W del
// pedido). Usa TODO el ancho disponible (nunca max-w-4xl con espacios en
// blanco laterales): contenido a la izquierda, resumen sticky a la derecha
// en desktop, una sola columna en mobile.
import { computed, onMounted, ref, watch } from 'vue'
import { useForm, Link } from '@inertiajs/vue3'
import { ArrowLeft, ArrowRight, Building2, CheckCircle2, Plus, Sparkles, Target, UserCog, Users } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import DatePickerField from '@/components/forms/DatePickerField.vue'
import TextareaField from '@/components/forms/TextareaField.vue'
import { Button } from '@/components/ui/button'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
import OkrWizardStepper from '@/components/okr/OkrWizardStepper.vue'
import OkrKeyResultCard from '@/components/okr/OkrKeyResultCard.vue'
import OkrWeightSummary from '@/components/okr/OkrWeightSummary.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    branches: { id: number; name: string }[]
    users: { id: number; name: string }[]
    currentUser: { id: number; name: string }
    kpis: any[]
    objectives: { id: number; title: string; branch_id: number | null }[]
    canManageResponsibles: boolean
}>()

const step = ref(1)
const steps = ['Contexto', 'Objective', 'Key Results', 'Revisión']

type KrRow = { kpi_id: number | null; description: string; baseline_value: string; target_value: string; weight: string }

const form = useForm({
    scope_type: 'branch' as 'branch' | 'employee',
    branch_id: null as number | null,
    employee_id: null as number | null,
    parent_id: null as number | null,
    title: '',
    responsible_user_id: props.currentUser.id as number | null,
    start_date: new Date().toISOString().slice(0, 10),
    duration_weeks: 8,
    key_results: [{ kpi_id: null, description: '', baseline_value: '', target_value: '', weight: '' }] as KrRow[],
})

// ── Responsables: "tú" primero y marcado, nunca una opción "yo mismo" separada
// que confunda con tu propio nombre en la misma lista. ──
const responsibleOptions = computed(() => [
    { id: props.currentUser.id, full_name: `${props.currentUser.name} (Tú)` },
    ...props.users.filter((u) => u.id !== props.currentUser.id),
])

function addKr() {
    form.key_results.push({ kpi_id: null, description: '', baseline_value: '', target_value: '', weight: '' })
}
function removeKr(i: number) {
    if (form.key_results.length > 1) form.key_results.splice(i, 1)
}

function kpiOf(id: number | null) {
    return props.kpis.find((k) => k.id === id)
}

const totalWeight = computed(() => form.key_results.reduce((sum, kr) => sum + (Number(kr.weight) || 0), 0))
const weightValid = computed(() => Math.abs(totalWeight.value - 100) < 0.01)

const branchObjectives = computed(() => props.objectives.filter((o) => !form.branch_id || o.branch_id === form.branch_id))

// ── Colaboradores de la sucursal elegida — búsqueda REMOTA real (bug
// corregido, punto 7 de la auditoría 09-sep-2026): antes solo se cargaba el
// top de resultados de la sucursal una vez y SearchableSelect filtraba en
// memoria — si había más de ~30 colaboradores, escribir un nombre que no
// estuviera en ese primer lote nunca lo encontraba. Ahora cada tecleo dispara
// (con debounce) la MISMA búsqueda remota que ya usa OkrFilters. ──
const employeeOptions = ref<{ id: number; full_name: string }[]>([])
const loadingEmployees = ref(false)
const selectedEmployeeCache = ref<{ id: number; full_name: string } | null>(null)
let employeeSearchTimer: ReturnType<typeof setTimeout> | null = null

async function loadEmployees(search = '') {
    if (!form.branch_id) { employeeOptions.value = []; return }
    loadingEmployees.value = true
    try {
        const params = new URLSearchParams({ branch_id: String(form.branch_id) })
        if (search) params.set('search', search)
        const res = await fetch(`/okr/employees-lookup?${params.toString()}`, { headers: { Accept: 'application/json' } })
        const data = await res.json()
        let list: { id: number; full_name: string }[] = data.employees ?? []
        // El colaborador ya elegido se mantiene visible en la lista aunque la
        // búsqueda actual no lo incluya — nunca "se pierde" al buscar otro nombre.
        if (selectedEmployeeCache.value && !list.some((e) => e.id === selectedEmployeeCache.value!.id)) {
            list = [selectedEmployeeCache.value, ...list]
        }
        employeeOptions.value = list
    } finally {
        loadingEmployees.value = false
    }
}

function onEmployeeSearch(term: string) {
    if (employeeSearchTimer) clearTimeout(employeeSearchTimer)
    employeeSearchTimer = setTimeout(() => loadEmployees(term), 300)
}

function onEmployeeChange(id: number | string | null) {
    selectedEmployeeCache.value = employeeOptions.value.find((e) => e.id === id) ?? null
}

watch(() => [form.scope_type, form.branch_id], () => {
    selectedEmployeeCache.value = null
    if (form.scope_type === 'employee') loadEmployees()
})

const selectedBranchName = computed(() => props.branches.find((b) => b.id === form.branch_id)?.name ?? null)
const selectedEmployeeName = computed(() => selectedEmployeeCache.value?.full_name ?? employeeOptions.value.find((e) => e.id === form.employee_id)?.full_name ?? null)

function canAdvance(): boolean {
    if (step.value === 1) {
        if (!form.branch_id) return false
        if (form.scope_type === 'employee' && !form.employee_id) return false
        return true
    }
    if (step.value === 2) {
        return form.title.trim().length >= 10
    }
    if (step.value === 3) {
        return form.key_results.every((kr) => kr.kpi_id && kr.description && kr.target_value !== '') && weightValid.value
    }
    return true
}

function next() { if (canAdvance() && step.value < steps.length) step.value++ }
function back() { if (step.value > 1) step.value-- }

function submit() {
    form.transform((data) => ({
        ...data,
        key_results: data.key_results.map((kr) => ({
            ...kr,
            baseline_value: kr.baseline_value === '' ? null : Number(kr.baseline_value),
            target_value: Number(kr.target_value),
            weight: Number(kr.weight),
        })),
    })).post('/okr')
}
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <Link href="/okr" class="text-muted-foreground transition hover:text-foreground"><ArrowLeft class="size-5" /></Link>
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-foreground">Asignar OKR</h1>
                    <p class="text-sm text-muted-foreground">Define el objetivo, sus resultados clave y actívalo cuando la línea base esté lista.</p>
                </div>
            </div>
            <OkrWizardStepper :steps="steps" :current="step" />
        </div>

        <div v-if="Object.keys(form.errors).length" class="animate-in fade-in rounded-2xl border border-destructive/30 bg-destructive/5 p-4 text-sm font-semibold text-destructive duration-200">
            <p v-for="(err, key) in form.errors" :key="key">{{ err }}</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <!-- Contenido del paso -->
            <div>
                <!-- PASO 1: Contexto -->
                <div v-if="step === 1" class="app-card animate-in fade-in space-y-5 p-6 duration-200">
                    <div class="flex items-center gap-1.5">
                        <p class="text-sm font-bold text-foreground">¿A qué nivel aplica este OKR?</p>
                        <OkrHelpTooltip text="Sucursal: mide el desempeño de toda una sucursal. Colaborador/Gestor: mide el desempeño de una persona específica dentro de una sucursal." />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            type="button"
                            class="group flex items-center gap-3 rounded-2xl border-2 p-4 text-left transition-all duration-200"
                            :class="form.scope_type === 'branch' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/30 hover:bg-muted/40'"
                            @click="form.scope_type = 'branch'; form.employee_id = null"
                        >
                            <span class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary transition-transform group-hover:scale-110"><Building2 class="size-5" /></span>
                            <span>
                                <span class="block text-sm font-bold text-foreground">Sucursal</span>
                                <span class="block text-xs text-muted-foreground">Objetivo a nivel de sucursal completa</span>
                            </span>
                        </button>
                        <button
                            type="button"
                            class="group flex items-center gap-3 rounded-2xl border-2 p-4 text-left transition-all duration-200"
                            :class="form.scope_type === 'employee' ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/30 hover:bg-muted/40'"
                            @click="form.scope_type = 'employee'"
                        >
                            <span class="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary transition-transform group-hover:scale-110"><Users class="size-5" /></span>
                            <span>
                                <span class="block text-sm font-bold text-foreground">Colaborador / Gestor</span>
                                <span class="block text-xs text-muted-foreground">Objetivo de una persona concreta</span>
                            </span>
                        </button>
                    </div>

                    <div class="space-y-1.5">
                        <label class="text-sm font-semibold text-foreground">Sucursal</label>
                        <SearchableSelect
                            v-model="form.branch_id as any"
                            placeholder="Selecciona una sucursal"
                            search-placeholder="Buscar sucursal..."
                            :options="branches"
                            label-key="name"
                            secondary-key="__none"
                            :error="form.errors.branch_id"
                        />
                        <p v-if="form.scope_type === 'employee'" class="text-xs text-muted-foreground">Elige primero la sucursal para buscar al colaborador dentro de ella.</p>
                    </div>

                    <div v-if="form.scope_type === 'employee'" class="space-y-4 animate-in fade-in duration-200">
                        <div class="space-y-1.5">
                            <label class="text-sm font-semibold text-foreground">Colaborador</label>
                            <SearchableSelect
                                v-model="form.employee_id as any"
                                :placeholder="!form.branch_id ? 'Primero elige una sucursal' : 'Buscar colaborador...'"
                                :search-placeholder="loadingEmployees ? 'Buscando...' : 'Escribe un nombre...'"
                                :options="employeeOptions"
                                label-key="full_name"
                                secondary-key="__none"
                                :disabled="!form.branch_id"
                                :error="form.errors.employee_id"
                                @update:search="onEmployeeSearch"
                                @change="onEmployeeChange"
                            />
                            <p class="text-xs text-muted-foreground">Escribe para buscar entre TODOS los colaboradores de esta sucursal (no solo los primeros) — misma fuente que Reportería.</p>
                        </div>
                        <div class="space-y-1.5">
                            <div class="flex items-center gap-1.5">
                                <label class="text-sm font-semibold text-foreground">Objective de sucursal al que contribuye (opcional)</label>
                                <OkrHelpTooltip text="Vincula este OKR individual a un Objective de sucursal más amplio, para verlo agrupado en el detalle de la sucursal." />
                            </div>
                            <SearchableSelect
                                v-model="form.parent_id as any"
                                placeholder="— Ninguno —"
                                :options="branchObjectives"
                                label-key="title"
                                secondary-key="__none"
                                allow-null
                                null-label="Ninguno"
                            />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-1.5">
                            <div class="flex items-center gap-1.5">
                                <label class="text-sm font-semibold text-foreground">Responsable</label>
                                <OkrHelpTooltip text="Quién da seguimiento a este OKR (check-ins, evidencias). Por defecto eres tú — cámbialo si alguien más llevará el seguimiento." />
                            </div>
                            <SearchableSelect
                                v-model="form.responsible_user_id as any"
                                placeholder="Selecciona un responsable"
                                :options="responsibleOptions"
                                label-key="full_name"
                                secondary-key="__none"
                            />
                            <Link v-if="canManageResponsibles" href="/okr/responsibles" class="inline-block text-xs font-semibold text-primary hover:underline">
                                + Agregar o administrar responsables
                            </Link>
                        </div>
                        <DatePickerField v-model="form.start_date" label="Fecha de inicio" />
                    </div>

                    <div class="max-w-xs space-y-1.5">
                        <div class="flex items-center gap-1.5">
                            <label class="text-sm font-semibold text-foreground">Plazo</label>
                            <OkrHelpTooltip text="Duración total del OKR en semanas. El avance esperado se calcula proporcionalmente a las semanas transcurridas." />
                        </div>
                        <div class="relative">
                            <input v-model.number="form.duration_weeks" type="number" min="1" max="104" class="app-input pr-20">
                            <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">semanas</span>
                        </div>
                    </div>
                </div>

                <!-- PASO 2: Objective -->
                <div v-if="step === 2" class="app-card animate-in fade-in space-y-3 p-6 duration-200">
                    <div class="flex items-center gap-1.5">
                        <label class="text-sm font-semibold text-foreground">Objective</label>
                        <OkrHelpTooltip text="El Objective es CUALITATIVO — describe qué se quiere lograr (una dirección, no una cifra). Las cifras van en los Key Results del siguiente paso." />
                    </div>
                    <TextareaField v-model="form.title" placeholder="Ej. Aumentar la colocación de préstamos activos" :rows="3" description="Mínimo 10 caracteres — describe la meta, no un número." :error="form.errors.title" />
                </div>

                <!-- PASO 3: Key Results -->
                <div v-if="step === 3" class="animate-in fade-in space-y-4 duration-200">
                    <OkrKeyResultCard
                        v-for="(kr, i) in form.key_results"
                        :key="i"
                        :model-value="kr"
                        :index="i"
                        :kpis="kpis"
                        :removable="form.key_results.length > 1"
                        :scope-type="form.scope_type"
                        :branch-id="form.branch_id"
                        :employee-id="form.employee_id"
                        :start-date="form.start_date"
                        @update:model-value="(v) => (form.key_results[i] = v)"
                        @remove="removeKr(i)"
                    />

                    <Button type="button" variant="outline" class="h-11 gap-2 rounded-2xl border-dashed border-primary/40 text-primary hover:bg-primary/5" @click="addKr">
                        <Plus class="size-4" /> Agregar Key Result
                    </Button>

                    <OkrWeightSummary :total="totalWeight" />
                </div>

                <!-- PASO 4: Revisión -->
                <div v-if="step === 4" class="app-card animate-in fade-in space-y-5 p-6 duration-200">
                    <div class="flex items-center gap-2">
                        <Sparkles class="size-4 text-primary" />
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Revisión final</p>
                    </div>
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Objective</p>
                        <p class="text-base font-semibold text-foreground">{{ form.title }}</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 text-sm">
                        <p><span class="font-semibold text-muted-foreground">Alcance:</span> {{ form.scope_type === 'branch' ? selectedBranchName : selectedEmployeeName }} <span v-if="form.scope_type === 'employee'" class="text-muted-foreground">({{ selectedBranchName }})</span></p>
                        <p><span class="font-semibold text-muted-foreground">Plazo:</span> {{ form.duration_weeks }} semanas desde {{ form.start_date }}</p>
                    </div>
                    <div class="space-y-2">
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Key Results ({{ totalWeight.toFixed(2) }}% ponderado)</p>
                        <div v-for="(kr, i) in form.key_results" :key="i" class="rounded-xl border border-border bg-muted/30 p-3 text-xs">
                            <p class="font-semibold text-foreground">{{ kr.description }}</p>
                            <p class="text-muted-foreground">{{ kpiOf(kr.kpi_id)?.name }} — base {{ kr.baseline_value || '(auto)' }} → meta {{ kr.target_value }} — peso {{ kr.weight }}%</p>
                        </div>
                    </div>
                    <p class="rounded-xl bg-primary/5 p-3 text-xs text-primary">Al crear el OKR queda en BORRADOR. Desde el detalle podrás revisar la línea base y activarlo — al activar, la línea base queda congelada.</p>
                </div>

                <!-- Navegación -->
                <div class="mt-6 flex justify-between">
                    <Button type="button" variant="outline" class="h-11 gap-2 rounded-2xl" :disabled="step === 1" @click="back">
                        <ArrowLeft class="size-4" /> Atrás
                    </Button>
                    <Button v-if="step < steps.length" type="button" class="h-11 gap-2 rounded-2xl" :disabled="!canAdvance()" @click="next">
                        Siguiente <ArrowRight class="size-4" />
                    </Button>
                    <Button v-else type="button" class="h-11 gap-2 rounded-2xl bg-emerald-600 text-white hover:bg-emerald-500" :disabled="form.processing" @click="submit">
                        <CheckCircle2 class="size-4" /> Crear OKR (borrador)
                    </Button>
                </div>
            </div>

            <!-- Resumen sticky (desktop) -->
            <aside class="hidden lg:block">
                <div class="sticky top-6 space-y-4">
                    <div class="app-card space-y-3 p-5">
                        <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                            <Target class="size-3.5 text-primary" /> Resumen del OKR
                        </p>
                        <div class="space-y-2 text-sm">
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground">Alcance</span>
                                <span class="font-semibold text-foreground">{{ form.scope_type === 'branch' ? 'Sucursal' : 'Colaborador' }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground">Sucursal</span>
                                <span class="font-semibold text-foreground">{{ selectedBranchName ?? '—' }}</span>
                            </div>
                            <div v-if="form.scope_type === 'employee'" class="flex items-center justify-between">
                                <span class="text-muted-foreground">Colaborador</span>
                                <span class="truncate pl-2 font-semibold text-foreground">{{ selectedEmployeeName ?? '—' }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground">Plazo</span>
                                <span class="font-semibold text-foreground">{{ form.duration_weeks }} sem.</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-muted-foreground">Key Results</span>
                                <span class="font-semibold text-foreground">{{ form.key_results.length }}</span>
                            </div>
                        </div>
                    </div>

                    <OkrWeightSummary :total="totalWeight" />

                    <div class="app-card space-y-2 p-5 text-xs text-muted-foreground">
                        <p class="flex items-center gap-1.5 font-bold text-foreground"><UserCog class="size-3.5 text-primary" /> Consejo</p>
                        <p>Si un Key Result usa un KPI automático, deja la línea base vacía — se completa sola con el dato real de Reportería en cuanto actives el OKR.</p>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
