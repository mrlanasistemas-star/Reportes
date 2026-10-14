<script setup lang="ts">
// "Asignar OKR" — Dialog de 4 pasos (orquestador). Corrección integral
// 14-oct-2026: antes los OKR individuales nacían SIN Key Results (el gestor
// quedaba sin meta) y la línea base solo se consultaba a nivel sucursal. Ahora
// cada gestor seleccionado configura SUS KR, SU línea base (consultada para
// ESE gestor, nunca copiada de la sucursal), SU incremento y SU meta; los
// pesos suman 100% dentro de cada OKR. Lógica de cada paso en:
//   OkrGestorPicker · OkrKrEditor · OkrGoalsTable · OkrTrajectoryPanel · OkrWizardSummary
import { useForm } from '@inertiajs/vue3'
import { AlertTriangle, ArrowLeft, ArrowRight, Building2, CheckCircle2, Copy, Info, Loader2, Sparkles, Users, X } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, reactive, ref, watch } from 'vue'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { Switch } from '@/components/ui/switch'
import { useOkrBaselinePreview } from '@/composables/useOkrBaselinePreview'
import OkrGestorPicker from './OkrGestorPicker.vue'
import OkrGoalsTable from './OkrGoalsTable.vue'
import OkrKrEditor from './OkrKrEditor.vue'
import OkrTrajectoryPanel from './OkrTrajectoryPanel.vue'
import {
    avatarTone, cloneKrStructure, initialsOf, isAutomatic, krGoalIsComplete, krStructureIsComplete, newKrRow, serializeKr, totalWeight,
} from './okrWizard'
import type { GestorPlan, KrRow } from './okrWizard'
import OkrWizardStepper from './OkrWizardStepper.vue'
import OkrWizardSummary from './OkrWizardSummary.vue'

const props = defineProps<{
    branches: { id: number; name: string }[]
    kpis: any[]
    currentUser: { id: number; full_name: string }
    users: { id: number; full_name: string }[]
}>()

const open = defineModel<boolean>('open', { default: false })

const STEPS = ['Asignación', 'Key Results y KPIs', 'Metas, pesos y trayectoria', 'Resumen y confirmación']
const step = ref(1)
const direction = ref<'forward' | 'back'>('forward')

const responsibleOptions = computed(() => {
    const others = props.users.filter((u) => u.id !== props.currentUser.id).map((u) => ({ id: u.id, full_name: u.full_name }))

    return [{ id: props.currentUser.id, full_name: `${props.currentUser.full_name} (Tú)` }, ...others]
})

const form = useForm({
    branch_id: null as number | null,
    title: '',
    responsible_user_id: props.currentUser.id as number | null,
    start_date: new Date().toISOString().slice(0, 10),
    duration_weeks: 8,
})

const branchRows = ref<KrRow[]>([])
const individualsEnabled = ref(false)
const gestores = reactive<GestorPlan[]>([])
// Pestaña activa del paso 2 / tarjeta abierta del paso 3: 'branch' o client_key de gestor.
const activeScope = ref<string>('branch')

function resetAll() {
    step.value = 1
    form.reset()
    form.clearErrors()
    form.responsible_user_id = props.currentUser.id
    branchRows.value = []
    individualsEnabled.value = false
    gestores.splice(0, gestores.length)
    employeeOptions.value = []
    activeScope.value = 'branch'
}

watch(open, (isOpen) => {
    if (isOpen) {
        resetAll()
    }
})

watch(individualsEnabled, (enabled) => {
    if (!enabled) {
        gestores.splice(0, gestores.length)
        activeScope.value = 'branch'
    }
})

// ── Colaboradores vigentes de la sucursal (búsqueda remota, nunca precarga todos). ──
const employeeOptions = ref<{ id: number; full_name: string }[]>([])
const branchEmployeeCount = ref(0)
const rosterPeriod = ref<string | null>(null)
const loadingEmployees = ref(false)
let employeeSearchTimer: ReturnType<typeof setTimeout> | null = null
let employeeSearchVersion = 0

async function loadEmployees(search = '') {
    if (!form.branch_id) {
        employeeOptions.value = []
        branchEmployeeCount.value = 0

        return
    }

    const params = new URLSearchParams({ branch_id: String(form.branch_id) })

    if (search) {
        params.set('search', search)
    }

    const myVersion = ++employeeSearchVersion
    loadingEmployees.value = true

    try {
        const res = await fetch(`/okr/employees-lookup?${params.toString()}`, { cache: 'no-store', headers: { Accept: 'application/json' } })
        const data = res.ok ? await res.json() : { employees: [], total_in_branch: 0, roster_period: null }

        if (myVersion !== employeeSearchVersion) {
            return
        }

        employeeOptions.value = data.employees ?? []
        branchEmployeeCount.value = data.total_in_branch ?? 0
        rosterPeriod.value = data.roster_period ?? null
    } finally {
        if (myVersion === employeeSearchVersion) {
            loadingEmployees.value = false
        }
    }
}

function onEmployeeSearch(term: string) {
    if (employeeSearchTimer) {
        clearTimeout(employeeSearchTimer)
    }

    employeeSearchTimer = setTimeout(() => loadEmployees(term), 300)
}

watch(() => form.branch_id, () => {
    gestores.splice(0, gestores.length)
    activeScope.value = 'branch'
    // La línea base consultada pertenece a la sucursal anterior — se invalida.
    branchRows.value.forEach((kr) => {
        kr.preview = null

        if (isAutomatic(kpiOf(kr.kpi_id))) {
            kr.baseline_value = ''
        }
    })
    loadEmployees()
})

function toggleGestor(employee: { id: number; full_name: string }) {
    const idx = gestores.findIndex((g) => g.employee_id === employee.id)

    if (idx >= 0) {
        gestores.splice(idx, 1)

        return
    }

    gestores.push({
        client_key: crypto.randomUUID(), employee_id: employee.id, employee_name: employee.full_name,
        title: form.title.trim() ? `${form.title.trim()} — ${employee.full_name}`.slice(0, 500) : '',
        key_results: [],
    })
}

function removeGestor(i: number) {
    if (activeScope.value === gestores[i]?.client_key) {
        activeScope.value = 'branch'
    }

    gestores.splice(i, 1)
}

const selectedBranchName = computed(() => props.branches.find((b) => b.id === form.branch_id)?.name ?? null)
const selectedResponsibleName = computed(() => responsibleOptions.value.find((u) => u.id === form.responsible_user_id)?.full_name ?? '—')
const endDatePreview = computed(() => {
    const start = new Date(`${form.start_date}T00:00:00`)

    if (Number.isNaN(start.getTime())) {
        return null
    }

    start.setDate(start.getDate() + form.duration_weeks * 7 - 1)

    return start.toISOString().slice(0, 10)
})

function kpiOf(id: number | null) {
    return props.kpis.find((k) => k.id === id) ?? null
}

// ── Paso 2 ──
const activeGestor = computed(() => gestores.find((g) => g.client_key === activeScope.value) ?? null)

function copyBranchStructureTo(target: GestorPlan | 'all') {
    const targets = target === 'all' ? gestores : [target]
    targets.forEach((g) => {
        g.key_results = cloneKrStructure(branchRows.value)
    })
    Swal.fire({
        toast: true, position: 'top-end', icon: 'success', timer: 2200, showConfirmButton: false,
        title: target === 'all' ? 'KPI copiados a todos los gestores' : 'KPI copiados',
        text: 'Solo KPI, descripción y peso. Cada gestor captura su propia base y meta.',
    })
}

watch(step, (s) => {
    if (s === 2 && branchRows.value.length === 0) {
        branchRows.value.push(newKrRow({ weight: '100' }))
    }

    if (s === 2) {
        gestores.filter((g) => g.key_results.length === 0).forEach((g) => {
            g.key_results = branchRows.value.some((kr) => kr.kpi_id) ? cloneKrStructure(branchRows.value) : [newKrRow({ weight: '100' })]
        })
    }

    if (s === 3) {
        autoConsultBaselines()
    }
})

// ── Paso 3: consulta automática (en paralelo) de las bases de sucursal y de CADA gestor ──
const { consult } = useOkrBaselinePreview()

function autoConsultBaselines() {
    const scopeBase = { branch_id: form.branch_id, start_date: form.start_date }
    branchRows.value
        .filter((kr) => isAutomatic(kpiOf(kr.kpi_id)) && !kr.preview && !kr.loading && kr.baseline_mode !== 'manual_authorized')
        .forEach((kr) => consult(kr, { ...scopeBase, scope_type: 'branch' }))
    gestores.forEach((g) => g.key_results
        .filter((kr) => isAutomatic(kpiOf(kr.kpi_id)) && !kr.preview && !kr.loading && kr.baseline_mode !== 'manual_authorized')
        .forEach((kr) => consult(kr, { ...scopeBase, scope_type: 'employee', employee_id: g.employee_id })))
}

function gestorGoalsComplete(g: GestorPlan): boolean {
    return g.key_results.length > 0 && g.key_results.every((kr) => krGoalIsComplete(kr, kpiOf(kr.kpi_id)))
}

const branchGoalsComplete = computed(() => branchRows.value.length > 0 && branchRows.value.every((kr) => krGoalIsComplete(kr, kpiOf(kr.kpi_id))))

// ── Navegación con motivo visible de bloqueo (nunca un "Siguiente" muerto sin explicación) ──
const blocker = computed<string | null>(() => {
    if (step.value === 1) {
        if (!form.branch_id) {
            return 'Elige la sucursal.'
        }

        if (form.title.trim().length < 10) {
            return 'Escribe el objetivo general (mínimo 10 caracteres).'
        }

        if (individualsEnabled.value) {
            if (gestores.length === 0) {
                return 'Selecciona al menos un gestor o apaga los OKR individuales.'
            }

            const noTitle = gestores.find((g) => g.title.trim().length < 10)

            if (noTitle) {
                return `Escribe el objective individual de ${noTitle.employee_name}.`
            }
        }

        return null
    }

    if (step.value === 2) {
        if (!krStructureIsComplete(branchRows.value)) {
            return `Completa los KR de la sucursal (KPI, descripción y pesos al 100% — hoy ${totalWeight(branchRows.value)}%).`
        }

        const bad = gestores.find((g) => !krStructureIsComplete(g.key_results))

        return bad ? `Completa los KR de ${bad.employee_name} (pesos al 100% — hoy ${totalWeight(bad.key_results)}%).` : null
    }

    if (step.value === 3) {
        if (!branchGoalsComplete.value) {
            return 'Completa la línea base y la meta de la sucursal.'
        }

        const bad = gestores.find((g) => !gestorGoalsComplete(g))

        return bad ? `Falta la línea base o la meta de ${bad.employee_name}.` : null
    }

    return null
})

function next() {
    if (!blocker.value && step.value < 4) {
        direction.value = 'forward'
        step.value++
    }
}

function back() {
    if (step.value > 1) {
        direction.value = 'back'
        step.value--
    }
}

function goTo(target: number) {
    if (target < step.value) {
        direction.value = 'back'
        step.value = target
    }
}

function submit() {
    form.transform((data) => ({
        scope_type: 'branch',
        branch_id: data.branch_id,
        title: data.title,
        responsible_user_id: data.responsible_user_id,
        start_date: data.start_date,
        duration_weeks: data.duration_weeks,
        key_results: branchRows.value.map((kr) => serializeKr(kr, kpiOf(kr.kpi_id))),
        individual_objectives: individualsEnabled.value
            ? gestores.map((g) => ({ employee_id: g.employee_id, title: g.title, key_results: g.key_results.map((kr) => serializeKr(kr, kpiOf(kr.kpi_id))) }))
            : [],
    })).post('/okr', {
        onSuccess: () => {
            open.value = false
            Swal.fire({
                icon: 'success', title: 'OKR guardado en borrador',
                text: gestores.length ? `Sucursal + ${gestores.length} OKR individual(es), cada uno con su propia meta.` : 'Revisa la línea base y actívalo desde el detalle.',
                timer: 2600, showConfirmButton: false,
            })
        },
        onError: (errors) => {
            Swal.fire({ icon: 'error', title: 'No se pudo guardar el OKR', html: Object.values(errors).slice(0, 6).map((e) => `• ${e}`).join('<br>'), confirmButtonText: 'Revisar' })
        },
    })
}

const primaryBranchKr = computed(() => branchRows.value[0] ?? null)
const goalScope = computed(() => ({ branch_id: form.branch_id, start_date: form.start_date }))
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[92vh] w-[96vw] max-w-[96vw] flex-col gap-0 overflow-hidden p-0 sm:max-w-[1400px]"
            :show-close-button="false"
            @pointer-down-outside="(e) => e.preventDefault()"
            @interact-outside="(e) => e.preventDefault()"
        >
            <DialogTitle class="sr-only">Asignar OKR</DialogTitle>

            <!-- Header -->
            <div class="relative overflow-hidden border-b border-border bg-gradient-to-r from-slate-950 via-indigo-950 to-slate-900 px-6 py-4 text-white">
                <div class="pointer-events-none absolute -right-16 -top-16 size-48 rounded-full bg-indigo-500/20 blur-3xl" />
                <div class="pointer-events-none absolute -bottom-20 left-1/3 size-40 rounded-full bg-emerald-500/10 blur-3xl" />
                <div class="relative flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="flex size-10 items-center justify-center rounded-xl bg-white/10 ring-1 ring-white/20 backdrop-blur"><Sparkles class="size-5 text-indigo-200" /></span>
                        <div>
                            <p class="text-base font-bold">Asignar OKR</p>
                            <p class="text-xs text-indigo-200/80">Paso {{ step }} de 4 · {{ STEPS[step - 1] }}</p>
                        </div>
                    </div>
                    <button type="button" class="rounded-full p-1.5 text-white/70 transition hover:rotate-90 hover:bg-white/10 hover:text-white" aria-label="Cerrar" @click="open = false"><X class="size-4" /></button>
                </div>
            </div>

            <OkrWizardStepper class="border-b border-border" :steps="STEPS" :current="step" @go="goTo" />

            <div v-if="Object.keys(form.errors).length" class="mx-6 mt-3 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-xs font-semibold text-destructive animate-in fade-in slide-in-from-top-1">
                <p v-for="(err, key) in form.errors" :key="key">{{ err }}</p>
            </div>

            <!-- Body -->
            <div class="flex-1 overflow-y-auto px-6 py-5">
                <Transition mode="out-in" :enter-from-class="direction === 'forward' ? 'opacity-0 translate-x-6' : 'opacity-0 -translate-x-6'" enter-active-class="transition duration-300 ease-out" leave-active-class="transition duration-150 ease-in" leave-to-class="opacity-0">
                    <!-- PASO 1 -->
                    <div v-if="step === 1" key="s1" class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_280px]">
                        <div class="space-y-4">
                            <div class="space-y-1.5">
                                <label class="text-sm font-semibold text-foreground">Sucursal a asignar</label>
                                <SearchableSelect v-model="form.branch_id as any" placeholder="Selecciona una sucursal" search-placeholder="Buscar sucursal..." :options="branches" label-key="name" secondary-key="__none" :error="form.errors.branch_id" />
                            </div>

                            <div class="space-y-1.5">
                                <label class="text-sm font-semibold text-foreground">Objetivo general</label>
                                <textarea v-model="form.title" rows="3" maxlength="500" placeholder="Incrementar colocación de cartera impulsando el crecimiento sostenible de la sucursal..." class="app-textarea" />
                                <div class="flex items-center justify-between">
                                    <p v-if="form.title.length > 0 && form.title.trim().length < 10" class="text-xs font-semibold text-amber-600">Mínimo 10 caracteres.</p>
                                    <p class="ml-auto text-[11px] tabular-nums text-muted-foreground">{{ form.title.length }}/500</p>
                                </div>
                            </div>

                            <div class="space-y-1.5">
                                <div class="flex items-center gap-1.5">
                                    <label class="text-sm font-semibold text-foreground">Responsable</label>
                                    <OkrHelpTooltip text="Quién da seguimiento a este OKR (check-ins, evidencias). Por defecto eres tú." />
                                </div>
                                <SearchableSelect v-model="form.responsible_user_id as any" placeholder="Selecciona un responsable" :options="responsibleOptions" label-key="full_name" secondary-key="__none" />
                            </div>

                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition hover:border-primary/40 hover:bg-primary/5" :class="individualsEnabled ? 'border-primary/40 bg-primary/5' : 'border-border bg-muted/20'">
                                <Switch v-model="individualsEnabled" class="mt-0.5" />
                                <div>
                                    <p class="text-sm font-semibold text-foreground">Agregar OKR individuales por gestor</p>
                                    <p class="text-xs text-muted-foreground">Cada gestor elegido tendrá su propia línea base, incremento y meta. La meta de sucursal se conserva.</p>
                                </div>
                            </label>

                            <OkrGestorPicker
                                v-if="individualsEnabled"
                                :employees="employeeOptions" :selected="gestores" :loading="loadingEmployees" :roster-period="rosterPeriod" :branch-selected="!!form.branch_id"
                                @toggle="toggleGestor" @remove="removeGestor" @search="onEmployeeSearch"
                            />
                        </div>

                        <aside class="h-fit space-y-3 rounded-2xl border border-border bg-gradient-to-b from-muted/40 to-background p-4 lg:sticky lg:top-0">
                            <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Resumen de asignación</p>
                            <div class="flex items-center gap-2.5 rounded-xl bg-background p-2.5 shadow-sm">
                                <span class="flex size-8 items-center justify-center rounded-full bg-blue-500/10 text-blue-600"><Building2 class="size-4" /></span>
                                <div><p class="text-[10px] text-muted-foreground">Sucursal</p><p class="text-sm font-semibold text-foreground">{{ selectedBranchName ?? '—' }}</p></div>
                            </div>
                            <div class="flex items-center gap-2.5 rounded-xl bg-background p-2.5 shadow-sm">
                                <span class="flex size-8 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600"><Users class="size-4" /></span>
                                <div><p class="text-[10px] text-muted-foreground">Colaboradores vigentes</p><p class="text-sm font-semibold tabular-nums text-foreground">{{ branchEmployeeCount }}</p></div>
                            </div>
                            <div class="flex items-center gap-2.5 rounded-xl bg-background p-2.5 shadow-sm">
                                <span class="flex size-8 items-center justify-center rounded-full bg-violet-500/10 text-violet-600"><Sparkles class="size-4" /></span>
                                <div><p class="text-[10px] text-muted-foreground">Tipo</p><p class="text-sm font-semibold text-foreground">{{ gestores.length ? `Sucursal + ${gestores.length} individual(es)` : 'Sucursal' }}</p></div>
                            </div>
                            <div v-if="gestores.length" class="flex -space-x-2 pt-1">
                                <span v-for="g in gestores.slice(0, 7)" :key="g.client_key" class="flex size-8 items-center justify-center rounded-full bg-gradient-to-br text-[10px] font-bold text-white ring-2 ring-background transition hover:z-10 hover:-translate-y-1" :class="avatarTone(g.employee_id)" :title="g.employee_name">{{ initialsOf(g.employee_name) }}</span>
                                <span v-if="gestores.length > 7" class="flex size-8 items-center justify-center rounded-full bg-muted text-[10px] font-bold ring-2 ring-background">+{{ gestores.length - 7 }}</span>
                            </div>
                        </aside>
                    </div>

                    <!-- PASO 2 -->
                    <div v-else-if="step === 2" key="s2" class="space-y-4">
                        <div v-if="gestores.length" class="flex flex-wrap items-center gap-2">
                            <button
                                type="button" class="flex items-center gap-2 rounded-full border px-3 py-1.5 text-xs font-semibold transition hover:-translate-y-0.5 hover:shadow-sm"
                                :class="activeScope === 'branch' ? 'border-primary bg-primary text-primary-foreground shadow-md shadow-primary/20' : 'border-border bg-card text-foreground hover:border-primary/40'"
                                @click="activeScope = 'branch'"
                            >
                                <Building2 class="size-3.5" /> Sucursal
                                <CheckCircle2 v-if="krStructureIsComplete(branchRows)" class="size-3.5" :class="activeScope === 'branch' ? '' : 'text-emerald-500'" />
                            </button>
                            <button
                                v-for="g in gestores" :key="g.client_key" type="button"
                                class="flex items-center gap-2 rounded-full border py-1 pl-1 pr-3 text-xs font-semibold transition hover:-translate-y-0.5 hover:shadow-sm"
                                :class="activeScope === g.client_key ? 'border-primary bg-primary text-primary-foreground shadow-md shadow-primary/20' : 'border-border bg-card text-foreground hover:border-primary/40'"
                                @click="activeScope = g.client_key"
                            >
                                <span class="flex size-6 items-center justify-center rounded-full bg-gradient-to-br text-[9px] font-bold text-white" :class="avatarTone(g.employee_id)">{{ initialsOf(g.employee_name) }}</span>
                                <span class="max-w-[140px] truncate">{{ g.employee_name }}</span>
                                <CheckCircle2 v-if="krStructureIsComplete(g.key_results)" class="size-3.5" :class="activeScope === g.client_key ? '' : 'text-emerald-500'" />
                                <AlertTriangle v-else class="size-3.5" :class="activeScope === g.client_key ? '' : 'text-amber-500'" />
                            </button>
                        </div>

                        <Transition mode="out-in" enter-active-class="transition duration-200" enter-from-class="opacity-0 translate-y-1" leave-active-class="transition duration-100" leave-to-class="opacity-0">
                            <div v-if="activeScope === 'branch' || !activeGestor" key="branch" class="space-y-3">
                                <div>
                                    <p class="text-sm font-semibold text-foreground">Key Results de la sucursal {{ selectedBranchName }}</p>
                                    <p class="text-xs text-muted-foreground">Miden el objetivo general con TODA la colocación válida de la sucursal (incluye gestores sin meta individual).</p>
                                </div>
                                <OkrKrEditor v-model:rows="branchRows" :kpis="kpis" />
                                <Button v-if="gestores.length" type="button" variant="outline" size="sm" class="gap-1.5 rounded-xl" :disabled="!krStructureIsComplete(branchRows)" @click="copyBranchStructureTo('all')">
                                    <Copy class="size-3.5" /> Usar estos KPI para todos los gestores
                                </Button>
                            </div>
                            <div v-else :key="activeGestor.client_key" class="space-y-3">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex size-9 items-center justify-center rounded-full bg-gradient-to-br text-[11px] font-bold text-white shadow" :class="avatarTone(activeGestor.employee_id)">{{ initialsOf(activeGestor.employee_name) }}</span>
                                        <div>
                                            <p class="text-sm font-semibold text-foreground">Key Results de {{ activeGestor.employee_name }}</p>
                                            <p class="text-xs text-muted-foreground">Sus pesos suman 100% dentro de SU OKR (independiente de los demás gestores).</p>
                                        </div>
                                    </div>
                                    <Button type="button" variant="outline" size="sm" class="gap-1.5 rounded-xl" :disabled="!krStructureIsComplete(branchRows)" @click="copyBranchStructureTo(activeGestor)">
                                        <Copy class="size-3.5" /> Copiar KPI de la sucursal
                                    </Button>
                                </div>
                                <OkrKrEditor v-model:rows="activeGestor.key_results" :kpis="kpis" placeholder="Incrementar mi colocación de cartera" />
                            </div>
                        </Transition>

                        <p class="flex items-start gap-2 rounded-xl bg-muted/30 px-3 py-2.5 text-[11px] text-muted-foreground">
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            <span>Copiar KPI solo duplica KPI, descripción y peso. La línea base, el incremento y la meta de cada gestor se capturan en el siguiente paso.</span>
                        </p>
                    </div>

                    <!-- PASO 3 -->
                    <div v-else-if="step === 3" key="s3" class="space-y-6">
                        <section class="space-y-3">
                            <div class="flex items-center gap-2">
                                <span class="flex size-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600"><Building2 class="size-4" /></span>
                                <div>
                                    <p class="text-sm font-bold text-foreground">Meta de sucursal — {{ selectedBranchName }}</p>
                                    <p class="text-[11px] text-muted-foreground">Meta final = Línea base congelada + Incremento esperado (pp para KPI porcentuales).</p>
                                </div>
                                <CheckCircle2 v-if="branchGoalsComplete" class="ml-auto size-5 text-emerald-500" />
                            </div>
                            <OkrGoalsTable v-model:rows="branchRows" :kpis="kpis" :scope="{ ...goalScope, scope_type: 'branch' }" scope-label="de la sucursal" />
                            <OkrTrajectoryPanel
                                v-model:weeks="form.duration_weeks" :start-date="form.start_date" :end-date="endDatePreview"
                                :baseline="primaryBranchKr?.baseline_value ?? ''" :target="primaryBranchKr?.target_value ?? ''" :unit="kpiOf(primaryBranchKr?.kpi_id ?? null)?.unit"
                            />
                        </section>

                        <section v-if="gestores.length" class="space-y-3">
                            <div class="flex items-center gap-2">
                                <span class="flex size-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600"><Users class="size-4" /></span>
                                <div>
                                    <p class="text-sm font-bold text-foreground">Metas por gestor</p>
                                    <p class="text-[11px] text-muted-foreground">La línea base se consulta para CADA gestor con su propia colocación — nunca se copia la de la sucursal.</p>
                                </div>
                                <span class="ml-auto rounded-full bg-muted px-2.5 py-1 text-[11px] font-bold tabular-nums">{{ gestores.filter(gestorGoalsComplete).length }}/{{ gestores.length }} completos</span>
                            </div>
                            <details v-for="(g, gi) in gestores" :key="g.client_key" class="group overflow-hidden rounded-2xl border border-border bg-card transition hover:shadow-md open:shadow-md" :open="gi === 0 || !gestorGoalsComplete(g)">
                                <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 transition hover:bg-muted/30">
                                    <span class="flex size-9 items-center justify-center rounded-full bg-gradient-to-br text-[11px] font-bold text-white shadow transition group-open:scale-105" :class="avatarTone(g.employee_id)">{{ initialsOf(g.employee_name) }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-foreground">{{ g.employee_name }}</p>
                                        <p class="truncate text-[11px] text-muted-foreground">{{ g.title }}</p>
                                    </div>
                                    <Loader2 v-if="g.key_results.some((kr) => kr.loading)" class="size-4 animate-spin text-primary" />
                                    <span v-else-if="gestorGoalsComplete(g)" class="flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300"><CheckCircle2 class="size-3" /> Completo</span>
                                    <span v-else class="flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-300"><AlertTriangle class="size-3" /> Faltan datos</span>
                                    <ArrowRight class="size-4 text-muted-foreground transition group-open:rotate-90" />
                                </summary>
                                <div class="border-t border-border p-3">
                                    <OkrGoalsTable v-model:rows="g.key_results" :kpis="kpis" :scope="{ ...goalScope, scope_type: 'employee', employee_id: g.employee_id }" scope-label="del gestor" />
                                </div>
                            </details>
                        </section>
                    </div>

                    <!-- PASO 4 -->
                    <OkrWizardSummary
                        v-else key="s4"
                        :kpis="kpis" :title="form.title" :branch-name="selectedBranchName" :responsible-name="selectedResponsibleName"
                        :duration-weeks="form.duration_weeks" :start-date="form.start_date" :end-date="endDatePreview"
                        :branch-rows="branchRows" :gestores="gestores"
                    />
                </Transition>
            </div>

            <!-- Footer -->
            <div class="flex items-center justify-between gap-3 border-t border-border bg-muted/20 px-6 py-3">
                <Button v-if="step > 1" type="button" variant="outline" class="h-10 gap-1.5 rounded-xl transition hover:-translate-x-0.5" @click="back"><ArrowLeft class="size-4" /> Anterior</Button>
                <Button v-else type="button" variant="ghost" class="h-10 rounded-xl text-muted-foreground" @click="open = false">Cancelar</Button>

                <p v-if="blocker" class="hidden flex-1 items-center justify-center gap-1.5 text-center text-[11px] font-semibold text-amber-600 sm:flex dark:text-amber-400 animate-in fade-in"><AlertTriangle class="size-3.5 shrink-0" /> {{ blocker }}</p>

                <Button v-if="step < 4" type="button" class="h-10 gap-1.5 rounded-xl shadow-md shadow-primary/20 transition hover:translate-x-0.5 hover:shadow-lg" :disabled="!!blocker" @click="next">Siguiente <ArrowRight class="size-4" /></Button>
                <Button v-else type="button" class="h-10 gap-1.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white shadow-lg shadow-emerald-600/30 transition hover:-translate-y-0.5 hover:from-emerald-500 hover:to-teal-500" :disabled="form.processing" @click="submit">
                    <Loader2 v-if="form.processing" class="size-4 animate-spin" /><CheckCircle2 v-else class="size-4" /> Guardar OKR
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
