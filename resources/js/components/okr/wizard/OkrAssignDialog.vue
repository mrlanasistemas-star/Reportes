<script setup lang="ts">
// "Asignar OKR" — Dialog de 4 pasos. Reconstruido 10-sep-2026 para reproducir
// docs/imagenesOKR/3-8.png (auditoría de esa fecha, secciones 6-21): antes
// era una PÁGINA completa (Wizard.vue) con 4 pasos distintos (Contexto/
// Objective/Key Results/Revisión) y un modelo scope_type=branch OR employee.
// Ahora es un Dialog (nunca navega a otra página) con el flujo exacto de la
// referencia: Asignación → Key Results y KPIs → Metas, pesos y trayectoria →
// Resumen y confirmación — y el Objective de sucursal admite N "OKR
// individuales" en la MISMA asignación (bug de diseño corregido, sección 8/9).
import { computed, reactive, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import {
    ArrowLeft, ArrowRight, Building2, CheckCircle2, Loader2, Plus,
    Search, Sparkles, Trash2, Users, X,
} from 'lucide-vue-next'
import VueApexCharts from 'vue3-apexcharts'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import SelectField from '@/components/forms/SelectField.vue'
import DatePickerField from '@/components/forms/DatePickerField.vue'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
import { formatByUnit, formatFriendlyDate } from '@/lib/okrFormat'

const props = defineProps<{
    branches: { id: number; name: string }[]
    kpis: any[]
    currentUser: { id: number; full_name: string }
    users: { id: number; full_name: string }[]
}>()

const open = defineModel<boolean>('open', { default: false })

const STEPS = ['Asignación', 'Key Results y KPIs', 'Metas, pesos y trayectoria', 'Resumen y confirmación']
const step = ref(1)

type IndividualRow = { employee_id: number | null; employee_name: string; title: string }
type KrRow = { kpi_id: number | null; description: string; baseline_value: string; target_value: string; weight: string; baseline_mode: 'auto' | 'manual' }

// CORRECCIÓN 10-sep-2026 (punto 12 de la auditoría — bug real): antes
// `responsibleOptions` mezclaba el usuario actual `{id, full_name}` con
// `props.users` que traían `{id, name}` — cualquier responsable que NO fuera
// el usuario actual salía sin nombre visible (SearchableSelect usa
// `label-key="full_name"`, y esos registros no tenían esa clave). Ahora TODA
// la lista se normaliza a UNA sola forma `{id, full_name}` antes de usarse.
const responsibleOptions = computed(() => {
    const normalizedUsers = props.users.map((u) => ({ id: u.id, full_name: u.full_name }))
    const withoutCurrent = normalizedUsers.filter((u) => u.id !== props.currentUser.id)

    return [{ id: props.currentUser.id, full_name: `${props.currentUser.full_name} (Tú)` }, ...withoutCurrent]
})

const form = useForm({
    scope_type: 'branch' as const,
    branch_id: null as number | null,
    title: '',
    responsible_user_id: props.currentUser.id as number | null,
    start_date: new Date().toISOString().slice(0, 10),
    duration_weeks: 8,
    key_results: [] as KrRow[],
    individual_objectives: [] as { employee_id: number | null; title: string }[],
})

const individualsEnabled = ref(false)
const individualRows = reactive<IndividualRow[]>([])

watch(individualsEnabled, (enabled) => {
    if (!enabled) individualRows.splice(0, individualRows.length)
})

function resetAll() {
    step.value = 1
    form.reset()
    form.clearErrors()
    form.responsible_user_id = props.currentUser.id
    individualsEnabled.value = false
    individualRows.splice(0, individualRows.length)
}
watch(open, (isOpen) => { if (isOpen) resetAll() })

// ── Colaboradores de la sucursal — búsqueda remota (nunca precarga todos). ──
const employeeOptions = ref<{ id: number; full_name: string }[]>([])
let employeeSearchTimer: ReturnType<typeof setTimeout> | null = null
async function loadEmployees(search = '') {
    if (!form.branch_id) { employeeOptions.value = []; return }
    const params = new URLSearchParams({ branch_id: String(form.branch_id) })
    if (search) params.set('search', search)
    const res = await fetch(`/okr/employees-lookup?${params.toString()}`, { headers: { Accept: 'application/json' } })
    employeeOptions.value = res.ok ? (await res.json()).employees ?? [] : []
}
function onEmployeeSearch(term: string) {
    if (employeeSearchTimer) clearTimeout(employeeSearchTimer)
    employeeSearchTimer = setTimeout(() => loadEmployees(term), 300)
}
watch(() => form.branch_id, () => { individualRows.splice(0, individualRows.length); loadEmployees() })

const addEmployeeId = ref<number | null>(null)
function addIndividual() {
    if (!addEmployeeId.value) return
    if (individualRows.some((r) => r.employee_id === addEmployeeId.value)) return
    const employee = employeeOptions.value.find((e) => e.id === addEmployeeId.value)
    individualRows.push({ employee_id: addEmployeeId.value, employee_name: employee?.full_name ?? '', title: '' })
    addEmployeeId.value = null
}
function removeIndividual(i: number) { individualRows.splice(i, 1) }

const selectedBranchName = computed(() => props.branches.find((b) => b.id === form.branch_id)?.name ?? null)
const assignmentType = computed(() => individualsEnabled.value && individualRows.length ? 'Sucursal + Individual' : 'Sucursal')

// ── Paso 2: Key Results y KPIs ──
function addKr() {
    form.key_results.push({ kpi_id: null, description: '', baseline_value: '', target_value: '', weight: '', baseline_mode: 'auto' })
}
function removeKr(i: number) { form.key_results.splice(i, 1) }
watch(() => step.value, (s) => { if (s === 2 && form.key_results.length === 0) addKr() })

function kpiOf(id: number | null) { return props.kpis.find((k) => k.id === id) ?? null }
const totalWeight = computed(() => form.key_results.reduce((sum, kr) => sum + (Number(kr.weight) || 0), 0))
const weightValid = computed(() => Math.abs(totalWeight.value - 100) < 0.01)

const donutOptions = computed(() => ({
    chart: { sparkline: { enabled: true } },
    colors: [weightValid.value ? '#10b981' : '#f59e0b'],
    plotOptions: { radialBar: { hollow: { size: '68%' }, dataLabels: { value: { fontSize: '20px', fontWeight: 700, formatter: () => `${Math.round(totalWeight.value)}%` } } } },
}))

// ── Paso 3: Metas, pesos y trayectoria ──
function onIncrementInput(kr: KrRow, value: string) {
    kr.target_value = value === '' || kr.baseline_value === '' ? kr.target_value : String(round2(Number(kr.baseline_value) + Number(value)))
}
function onTargetInput(kr: KrRow, value: string) {
    kr.target_value = value
}
function incrementOf(kr: KrRow): string {
    if (kr.baseline_value === '' || kr.target_value === '') return ''
    return String(round2(Number(kr.target_value) - Number(kr.baseline_value)))
}
function round2(n: number): number { return Math.round(n * 100) / 100 }

// Baseline preview (KPI automático) — GET /okr/baseline-preview, nunca guarda nada.
const baselinePreviews = reactive<Record<number, { available: boolean; value: number | null; period: { label: string } | null } | null>>({})
const loadingPreview = reactive<Record<number, boolean>>({})
async function fetchBaselinePreview(index: number) {
    const kr = form.key_results[index]
    const kpi = kpiOf(kr.kpi_id)
    if (!kpi || !form.branch_id) return
    loadingPreview[index] = true
    try {
        const params = new URLSearchParams({ kpi_id: String(kpi.id), scope_type: 'branch', branch_id: String(form.branch_id), start_date: form.start_date })
        const res = await fetch(`/okr/baseline-preview?${params.toString()}`, { headers: { Accept: 'application/json' } })
        const data = res.ok ? await res.json() : null
        baselinePreviews[index] = data
        if (data?.available) kr.baseline_value = String(data.value)
    } finally {
        loadingPreview[index] = false
    }
}

// Trayectoria esperada — PREVISUALIZACIÓN cliente, misma fórmula lineal que
// OkrTrajectoryService::expectedValue() (interpolación base→meta por semana)
// — nunca una fórmula distinta, nunca escribe nada en el servidor.
const primaryKr = computed(() => form.key_results[0] ?? null)
const trajectoryPoints = computed(() => {
    const kr = primaryKr.value
    if (!kr || kr.baseline_value === '' || kr.target_value === '') return []
    const base = Number(kr.baseline_value)
    const target = Number(kr.target_value)
    const weeks = Math.max(1, form.duration_weeks)
    return Array.from({ length: weeks }, (_, i) => {
        const week = i + 1
        return Math.round((base + ((target - base) * week) / weeks) * 100) / 100
    })
})
const expectedAtWeek1Pct = computed(() => (form.duration_weeks > 0 ? Math.round((1 / form.duration_weeks) * 100) : 0))

const endDatePreview = computed(() => {
    const start = new Date(`${form.start_date}T00:00:00`)
    if (Number.isNaN(start.getTime())) return null
    start.setDate(start.getDate() + form.duration_weeks * 7 - 1)
    return start.toISOString().slice(0, 10)
})

const trajectoryChartOptions = computed(() => ({
    chart: { toolbar: { show: false }, sparkline: { enabled: false } },
    xaxis: { categories: trajectoryPoints.value.map((_, i) => `S${i + 1}`), labels: { style: { fontSize: '10px' } } },
    yaxis: { labels: { show: false } },
    colors: ['#4f46e5'],
    stroke: { curve: 'straight', width: 2 },
    grid: { show: false },
    dataLabels: { enabled: false },
}))

// ── Navegación ──
function canAdvance(): boolean {
    if (step.value === 1) {
        if (!form.branch_id || form.title.trim().length < 10) return false
        if (individualsEnabled.value) {
            return individualRows.every((r) => r.employee_id && r.title.trim().length >= 10)
        }
        return true
    }
    if (step.value === 2) {
        return form.key_results.length > 0 && form.key_results.every((kr) => kr.kpi_id && kr.description && kr.weight !== '') && weightValid.value
    }
    if (step.value === 3) {
        return form.key_results.every((kr) => kr.target_value !== '' && (kr.baseline_mode === 'auto' || kr.baseline_value !== ''))
    }
    return true
}
function next() { if (canAdvance() && step.value < 4) step.value++ }
function back() { if (step.value > 1) step.value-- }

function submit() {
    form.transform((data) => ({
        ...data,
        individual_objectives: individualsEnabled.value
            ? individualRows.map((r) => ({ employee_id: r.employee_id, title: r.title }))
            : [],
        key_results: data.key_results.map((kr) => ({
            kpi_id: kr.kpi_id,
            description: kr.description,
            baseline_value: kr.baseline_mode === 'manual' && kr.baseline_value !== '' ? Number(kr.baseline_value) : null,
            target_value: Number(kr.target_value),
            weight: Number(kr.weight),
        })),
    })).post('/okr', {
        onSuccess: () => { open.value = false },
    })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[90vh] w-full max-w-[1000px] flex-col gap-0 overflow-hidden p-0" :show-close-button="false">
            <DialogTitle class="sr-only">Asignar OKR</DialogTitle>

            <!-- Header -->
            <div class="flex items-center justify-between border-b border-border px-6 py-4">
                <div>
                    <p class="text-base font-bold text-foreground">Asignar OKR</p>
                    <p class="text-xs text-muted-foreground">Paso {{ step }} de 4 · {{ STEPS[step - 1] }}</p>
                </div>
                <button type="button" class="rounded-full p-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground" aria-label="Cerrar" @click="open = false">
                    <X class="size-4" />
                </button>
            </div>

            <!-- Stepper -->
            <div class="flex items-center gap-1 border-b border-border px-6 py-3">
                <template v-for="(label, i) in STEPS" :key="label">
                    <div class="flex items-center gap-1.5">
                        <span
                            class="flex size-6 items-center justify-center rounded-full text-[11px] font-bold"
                            :class="i + 1 < step ? 'bg-primary text-primary-foreground' : i + 1 === step ? 'bg-primary text-primary-foreground ring-2 ring-primary/25' : 'bg-muted text-muted-foreground'"
                        >
                            <CheckCircle2 v-if="i + 1 < step" class="size-3.5" />
                            <span v-else>{{ i + 1 }}</span>
                        </span>
                        <span class="hidden text-xs font-semibold sm:inline" :class="i + 1 <= step ? 'text-foreground' : 'text-muted-foreground'">{{ label }}</span>
                    </div>
                    <div v-if="i < STEPS.length - 1" class="mx-1.5 h-px flex-1" :class="i + 1 < step ? 'bg-primary' : 'bg-border'" />
                </template>
            </div>

            <div v-if="Object.keys(form.errors).length" class="mx-6 mt-3 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-xs font-semibold text-destructive">
                <p v-for="(err, key) in form.errors" :key="key">{{ err }}</p>
            </div>

            <!-- Body -->
            <div class="flex-1 overflow-y-auto px-6 py-5">
                <!-- PASO 1: Asignación -->
                <div v-if="step === 1" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_260px]">
                    <div class="space-y-4">
                        <div class="space-y-1.5">
                            <label class="text-sm font-semibold text-foreground">Sucursal a asignar</label>
                            <SearchableSelect v-model="form.branch_id as any" placeholder="Selecciona una sucursal" search-placeholder="Buscar sucursal..." :options="branches" label-key="name" secondary-key="__none" :error="form.errors.branch_id" />
                        </div>

                        <div class="space-y-1.5">
                            <label class="text-sm font-semibold text-foreground">Objective</label>
                            <textarea v-model="form.title" rows="3" maxlength="500" placeholder="Aumentar la colocación de préstamos activos impulsando el crecimiento sostenible de la cartera..." class="app-textarea" />
                            <div class="flex items-center justify-between">
                                <p v-if="form.errors.title" class="text-xs font-semibold text-destructive">{{ form.errors.title }}</p>
                                <p class="ml-auto text-[11px] text-muted-foreground">{{ form.title.length }}/500</p>
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <div class="flex items-center gap-1.5">
                                <label class="text-sm font-semibold text-foreground">Responsable</label>
                                <OkrHelpTooltip text="Quién da seguimiento a este OKR (check-ins, evidencias). Por defecto eres tú." />
                            </div>
                            <SearchableSelect v-model="form.responsible_user_id as any" placeholder="Selecciona un responsable" :options="responsibleOptions" label-key="full_name" secondary-key="__none" />
                        </div>

                        <div class="flex items-start gap-3 rounded-xl border border-border bg-muted/20 p-3">
                            <Switch v-model="individualsEnabled" class="mt-0.5" />
                            <div>
                                <p class="text-sm font-semibold text-foreground">Agregar OKR individuales por vendedor</p>
                                <p class="text-xs text-muted-foreground">Crea objetivos individuales para cada colaborador de la sucursal seleccionada.</p>
                            </div>
                        </div>

                        <div v-if="individualsEnabled" class="space-y-3 animate-in fade-in duration-200">
                            <p class="text-sm font-semibold text-foreground">Asignación individual</p>
                            <div class="flex gap-2">
                                <div class="relative flex-1">
                                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <SearchableSelect
                                        v-model="addEmployeeId as any"
                                        placeholder="Agregar colaborador"
                                        :options="employeeOptions.filter((e) => !individualRows.some((r) => r.employee_id === e.id))"
                                        label-key="full_name" secondary-key="__none"
                                        :disabled="!form.branch_id"
                                        @update:search="onEmployeeSearch"
                                        @change="addIndividual"
                                    />
                                </div>
                            </div>

                            <div v-if="individualRows.length" class="space-y-2">
                                <div v-for="(row, i) in individualRows" :key="row.employee_id" class="flex items-start gap-2 rounded-xl border border-border p-2.5">
                                    <span class="mt-1 flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary">{{ row.employee_name.slice(0, 2).toUpperCase() }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="mb-1 truncate text-xs font-semibold text-foreground">{{ row.employee_name }}</p>
                                        <input v-model="row.title" placeholder="Objective individual (mín. 10 caracteres)" class="app-input h-9 text-xs">
                                    </div>
                                    <button type="button" class="mt-1 shrink-0 text-muted-foreground transition hover:text-destructive" @click="removeIndividual(i)"><X class="size-4" /></button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <aside class="app-card h-fit space-y-3 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Resumen de asignación</p>
                        <div class="flex items-center gap-2 text-sm">
                            <Building2 class="size-4 text-primary" />
                            <div><p class="text-xs text-muted-foreground">Sucursal</p><p class="font-semibold text-foreground">{{ selectedBranchName ?? '—' }}</p></div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <Users class="size-4 text-primary" />
                            <div><p class="text-xs text-muted-foreground">Colaboradores</p><p class="font-semibold text-foreground">{{ individualRows.length }}</p></div>
                        </div>
                        <div class="flex items-center gap-2 text-sm">
                            <Sparkles class="size-4 text-primary" />
                            <div><p class="text-xs text-muted-foreground">Tipo</p><p class="font-semibold text-foreground">{{ assignmentType }}</p></div>
                        </div>
                    </aside>
                </div>

                <!-- PASO 2: Key Results y KPIs -->
                <div v-else-if="step === 2" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_240px]">
                    <div class="space-y-4">
                        <div class="flex items-center gap-3 rounded-xl border border-border bg-muted/20 p-3">
                            <span class="flex size-9 items-center justify-center rounded-full bg-primary/10 text-primary"><Sparkles class="size-4" /></span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-foreground">{{ form.title || 'Objective sin título' }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ selectedBranchName }}</p>
                            </div>
                        </div>

                        <div>
                            <p class="text-sm font-semibold text-foreground">Resultados clave a medir</p>
                            <p class="text-xs text-muted-foreground">Define los resultados clave que impulsarán el cumplimiento del objetivo y los KPI asociados.</p>
                        </div>

                        <div class="overflow-x-auto rounded-xl border border-border">
                            <table class="w-full min-w-[640px] text-xs">
                                <thead class="border-b border-border bg-muted/30 text-muted-foreground">
                                    <tr>
                                        <th class="w-8 px-2 py-2"></th>
                                        <th class="px-2 py-2 text-left font-semibold">Resultado clave</th>
                                        <th class="px-2 py-2 text-left font-semibold">KPI relacionado</th>
                                        <th class="px-2 py-2 text-left font-semibold">Tipo</th>
                                        <th class="px-2 py-2 text-left font-semibold">Dirección</th>
                                        <th class="w-20 px-2 py-2 text-left font-semibold">Peso</th>
                                        <th class="w-8 px-2 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(kr, i) in form.key_results" :key="i" class="border-t border-border">
                                        <td class="px-2 py-2 text-center text-muted-foreground">{{ i + 1 }}</td>
                                        <td class="px-2 py-2"><input v-model="kr.description" placeholder="Incrementar colocación de cartera" class="app-input h-9 text-xs"></td>
                                        <td class="min-w-[140px] px-2 py-2">
                                            <SelectField
                                                :model-value="kr.kpi_id !== null ? String(kr.kpi_id) : null"
                                                placeholder="KPI"
                                                :options="kpis.map((k) => ({ value: String(k.id), label: k.name }))"
                                                @update:model-value="(v) => (kr.kpi_id = Number(v))"
                                            />
                                        </td>
                                        <td class="whitespace-nowrap px-2 py-2 text-muted-foreground">{{ kpiOf(kr.kpi_id)?.type ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-2 py-2">
                                            <span v-if="kpiOf(kr.kpi_id)" class="rounded-full px-2 py-0.5 text-[10px] font-semibold" :class="kpiOf(kr.kpi_id).direction === 'increase' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'">
                                                {{ kpiOf(kr.kpi_id).direction === 'increase' ? 'Incrementar ↑' : 'Disminuir ↓' }}
                                            </span>
                                            <span v-else class="text-muted-foreground">—</span>
                                        </td>
                                        <td class="px-2 py-2"><input v-model="kr.weight" type="number" step="0.01" min="0.01" max="100" placeholder="0" class="app-input h-9 text-xs"></td>
                                        <td class="px-2 py-2 text-center">
                                            <button v-if="form.key_results.length > 1" type="button" class="text-muted-foreground transition hover:text-destructive" @click="removeKr(i)"><Trash2 class="size-3.5" /></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <Button type="button" variant="outline" size="sm" class="gap-1.5 rounded-xl border-dashed" @click="addKr">
                            <Plus class="size-3.5" /> Agregar Key Result
                        </Button>
                    </div>

                    <aside class="app-card h-fit space-y-3 p-4 text-center">
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Resumen del OKR</p>
                        <div class="mx-auto w-28"><VueApexCharts type="radialBar" :height="112" :options="donutOptions" :series="[Math.min(100, totalWeight)]" /></div>
                        <p class="text-xs text-muted-foreground">Pesos asignados</p>
                        <p class="text-sm font-semibold text-foreground">{{ form.key_results.length }} KPI seleccionados</p>
                        <p class="text-[11px] text-muted-foreground">El cumplimiento del OKR se calcula por ponderación.</p>
                    </aside>
                </div>

                <!-- PASO 3: Metas, pesos y trayectoria -->
                <div v-else-if="step === 3" class="space-y-5">
                    <p class="text-sm font-semibold text-foreground">Definición de metas por KPI</p>

                    <div class="overflow-x-auto rounded-xl border border-border">
                        <table class="w-full min-w-[760px] text-xs">
                            <thead class="border-b border-border bg-muted/30 text-muted-foreground">
                                <tr>
                                    <th class="px-2 py-2 text-left font-semibold">KPI</th>
                                    <th class="px-2 py-2 text-left font-semibold">Tipo</th>
                                    <th class="px-2 py-2 text-left font-semibold">Dirección</th>
                                    <th class="px-2 py-2 text-left font-semibold">Base congelada</th>
                                    <th class="px-2 py-2 text-left font-semibold">Incremento esperado</th>
                                    <th class="px-2 py-2 text-left font-semibold">Meta objetivo</th>
                                    <th class="w-16 px-2 py-2 text-left font-semibold">Peso</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(kr, i) in form.key_results" :key="i" class="border-t border-border align-top">
                                    <td class="px-2 py-2.5 font-medium text-foreground">{{ kpiOf(kr.kpi_id)?.name ?? '—' }}</td>
                                    <td class="px-2 py-2.5 text-muted-foreground">{{ kpiOf(kr.kpi_id)?.type ?? '—' }}</td>
                                    <td class="px-2 py-2.5 text-muted-foreground">{{ kpiOf(kr.kpi_id)?.direction === 'increase' ? 'Incrementar ↑' : 'Disminuir ↓' }}</td>
                                    <td class="min-w-[140px] px-2 py-2.5">
                                        <template v-if="kpiOf(kr.kpi_id)?.automation === 'automatic'">
                                            <div class="rounded-lg border border-border bg-muted/20 px-2 py-1.5">
                                                <p class="font-semibold tabular-nums text-foreground">{{ kr.baseline_value !== '' ? formatByUnit(kr.baseline_value, kpiOf(kr.kpi_id)?.unit) : 'Sin consultar' }}</p>
                                                <button type="button" class="mt-1 inline-flex items-center gap-1 text-[11px] font-semibold text-primary" @click="fetchBaselinePreview(i)">
                                                    <Loader2 v-if="loadingPreview[i]" class="size-3 animate-spin" /><Search v-else class="size-3" /> Consultar
                                                </button>
                                                <p v-if="baselinePreviews[i]?.available" class="mt-0.5 text-[10px] text-muted-foreground">Fuente: Reportería · {{ baselinePreviews[i]?.period?.label }}</p>
                                                <p v-else-if="baselinePreviews[i] && !baselinePreviews[i]?.available" class="mt-0.5 text-[10px] text-destructive">No disponible todavía</p>
                                            </div>
                                        </template>
                                        <input v-else v-model="kr.baseline_value" type="number" step="0.01" placeholder="0.00" class="app-input h-9 text-xs" @change="kr.baseline_mode = 'manual'">
                                    </td>
                                    <td class="min-w-[110px] px-2 py-2.5">
                                        <input :value="incrementOf(kr)" type="number" step="0.01" placeholder="+0.00" class="app-input h-9 text-xs" @input="(e) => onIncrementInput(kr, (e.target as HTMLInputElement).value)">
                                    </td>
                                    <td class="min-w-[110px] px-2 py-2.5">
                                        <input v-model="kr.target_value" type="number" step="0.01" placeholder="0.00" class="app-input h-9 text-xs" @input="(e) => onTargetInput(kr, (e.target as HTMLInputElement).value)">
                                    </td>
                                    <td class="px-2 py-2.5 font-semibold tabular-nums text-foreground">{{ kr.weight }}%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="flex items-start gap-2 rounded-xl bg-muted/30 px-3 py-2.5 text-[11px] text-muted-foreground">
                        <span class="mt-0.5">ⓘ</span>
                        <span>Fórmula de cálculo de la meta: <strong class="text-foreground">Meta = Base congelada + Incremento esperado</strong>. Para KPI porcentuales, el incremento se expresa en puntos porcentuales (pp).</span>
                    </p>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div class="app-card space-y-2 p-4">
                            <p class="text-xs font-semibold text-foreground">Plazo de evaluación</p>
                            <p class="text-[11px] text-muted-foreground">Define el horizonte temporal para medir el avance del OKR.</p>
                            <input v-model.number="form.duration_weeks" type="number" min="1" max="104" class="app-input h-9 w-28 text-xs">
                            <p class="text-[11px] text-muted-foreground">semanas</p>
                            <div class="pt-1 text-[11px] text-muted-foreground">
                                <p>Inicio: {{ formatFriendlyDate(form.start_date) }}</p>
                                <p>Término: {{ endDatePreview ? formatFriendlyDate(endDatePreview) : '—' }}</p>
                            </div>
                        </div>
                        <div class="app-card space-y-2 p-4">
                            <p class="text-xs font-semibold text-foreground">Trayectoria esperada</p>
                            <p class="text-[11px] text-muted-foreground">Proyección del avance acumulado durante el periodo.</p>
                            <VueApexCharts v-if="trajectoryPoints.length > 1" type="line" :height="110" :options="trajectoryChartOptions" :series="[{ name: 'Esperado', data: trajectoryPoints }]" />
                            <p v-else class="py-6 text-center text-[11px] text-muted-foreground">Captura base y meta para ver la trayectoria.</p>
                        </div>
                        <div class="app-card space-y-2 p-4">
                            <p class="text-xs font-semibold text-foreground">Avance esperado vs. meta final</p>
                            <p class="text-[11px] text-muted-foreground">Comparación del avance esperado al cierre frente a la meta.</p>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-center">
                                <div class="rounded-lg bg-muted/30 p-2"><p class="text-lg font-bold text-foreground">{{ expectedAtWeek1Pct }}%</p><p class="text-[10px] text-muted-foreground">Semana 1</p></div>
                                <div class="rounded-lg bg-primary/10 p-2"><p class="text-lg font-bold text-primary">100%</p><p class="text-[10px] text-muted-foreground">Meta final</p></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PASO 4: Resumen y confirmación -->
                <div v-else-if="step === 4" class="space-y-5">
                    <div class="app-card space-y-2 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Objective</p>
                        <p class="text-sm font-semibold text-foreground">{{ form.title }}</p>
                        <div class="grid gap-2 pt-1 text-xs sm:grid-cols-3">
                            <p><span class="text-muted-foreground">Sucursal:</span> <span class="font-semibold text-foreground">{{ selectedBranchName }}</span></p>
                            <p><span class="text-muted-foreground">Plazo:</span> <span class="font-semibold text-foreground">{{ form.duration_weeks }} semanas</span></p>
                            <p><span class="text-muted-foreground">Inicio → Término:</span> <span class="font-semibold text-foreground">{{ formatFriendlyDate(form.start_date) }} → {{ endDatePreview ? formatFriendlyDate(endDatePreview) : '—' }}</span></p>
                        </div>
                    </div>

                    <div v-if="individualsEnabled && individualRows.length" class="app-card space-y-2 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">OKR individuales ({{ individualRows.length }})</p>
                        <div v-for="row in individualRows" :key="row.employee_id" class="rounded-lg border border-border bg-muted/20 p-2 text-xs">
                            <p class="font-semibold text-foreground">{{ row.employee_name }}</p>
                            <p class="text-muted-foreground">{{ row.title }}</p>
                        </div>
                    </div>

                    <div class="app-card space-y-2 p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Key Results ({{ totalWeight.toFixed(2) }}% ponderado)</p>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="weightValid ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-destructive/10 text-destructive'">{{ weightValid ? 'Listo para guardar' : 'Los pesos deben sumar 100%' }}</span>
                        </div>
                        <div v-for="(kr, i) in form.key_results" :key="i" class="rounded-lg border border-border bg-muted/20 p-2 text-xs">
                            <p class="font-semibold text-foreground">{{ kr.description }} — {{ kpiOf(kr.kpi_id)?.name }}</p>
                            <p class="text-muted-foreground">Base {{ kr.baseline_value || '(auto)' }} → Meta {{ kr.target_value }} · Peso {{ kr.weight }}%</p>
                        </div>
                    </div>

                    <p class="rounded-xl bg-primary/5 px-3 py-2.5 text-xs text-primary">El OKR se guardará en BORRADOR. Podrás revisar la línea base y activarlo desde el detalle.</p>
                </div>
            </div>

            <!-- Footer sticky -->
            <div class="flex items-center justify-between border-t border-border px-6 py-3">
                <Button v-if="step > 1" type="button" variant="outline" class="h-10 gap-1.5 rounded-xl" @click="back"><ArrowLeft class="size-4" /> Anterior</Button>
                <Button v-else type="button" variant="ghost" class="h-10 rounded-xl text-muted-foreground" @click="open = false">Cancelar</Button>

                <Button v-if="step < 4" type="button" class="h-10 gap-1.5 rounded-xl" :disabled="!canAdvance()" @click="next">Siguiente <ArrowRight class="size-4" /></Button>
                <Button v-else type="button" class="h-10 gap-1.5 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500" :disabled="!weightValid || form.processing" @click="submit">
                    <CheckCircle2 class="size-4" /> Guardar OKR
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
