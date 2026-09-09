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
    AlertTriangle, ArrowLeft, ArrowRight, Building2, CalendarDays, CheckCircle2, GripVertical, Hash, Info, ListChecks, Loader2, Percent, Plus,
    Search, Sparkles, Trash2, UserCheck, Users, Users2, Wallet, X,
} from 'lucide-vue-next'
import VueApexCharts from 'vue3-apexcharts'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
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
// type_filter/direction_filter son SOLO estado de UI (nunca se envían al
// backend — el submit() reconstruye cada key_result con únicamente
// kpi_id/description/baseline_value/target_value/weight, sección 234-239):
// sirven para acotar qué KPI del catálogo puede elegirse en "KPI relacionado"
// sin inventar columnas nuevas en okr_key_results (Tipo y Dirección siguen
// siendo, como siempre, atributos del KPI del catálogo — nunca del Key Result).
type KrRow = {
    kpi_id: number | null
    description: string
    baseline_value: string
    target_value: string
    weight: string
    baseline_mode: 'auto' | 'manual'
    type_filter: string | null
    direction_filter: string | null
}

// Traducciones ES de los enums crudos de OkrKpi (app/Models/OkrKpi.php) — el
// bug reportado era mostrar el valor crudo en inglés ("cumulative", etc.) sin
// traducir en la tabla de Key Results.
const KPI_TYPE_LABELS: Record<string, string> = { cumulative: 'Acumulativo', balance: 'Saldo', percentage: 'Porcentual' }
const KPI_DIRECTION_LABELS: Record<string, string> = { increase: 'Incrementar', decrease: 'Disminuir' }
const ALL_FILTER = '__all__'
function kpiTypeLabel(type?: string | null) { return type ? (KPI_TYPE_LABELS[type] ?? type) : '—' }
function kpiDirectionLabel(direction?: string | null) { return direction ? (KPI_DIRECTION_LABELS[direction] ?? direction) : '—' }

// Ícono + color por KPI (docs/imagenesOKR/7.png y 8.png muestran un ícono
// circular a la izquierda del nombre en "Definición de metas por KPI"). El
// catálogo (okr_kpis) no tiene una columna de ícono/color propia — inventar
// una hubiera sido otro cambio de esquema no pedido — así que se deriva de
// `unit`, que sí es un dato real del KPI: currency → billetera, percentage →
// porcentaje, integer/conteo → numeral.
function kpiIconFor(kpi: any): { icon: any; class: string } {
    if (!kpi) return { icon: Hash, class: 'bg-muted text-muted-foreground' }
    if (kpi.unit === 'currency') return { icon: Wallet, class: 'bg-blue-500/10 text-blue-600 dark:text-blue-300' }
    if (kpi.unit === 'percentage') return { icon: Percent, class: 'bg-rose-500/10 text-rose-600 dark:text-rose-300' }
    return { icon: Hash, class: 'bg-violet-500/10 text-violet-600 dark:text-violet-300' }
}

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
    employeeOptions.value = []
    branchEmployeeCount.value = 0
}
watch(open, (isOpen) => { if (isOpen) resetAll() })

// ── Colaboradores de la sucursal — búsqueda remota (nunca precarga todos). ──
const employeeOptions = ref<{ id: number; full_name: string }[]>([])
// Total REAL de colaboradores de la sucursal (sin el límite de 30 del
// buscador) — se usa en "Resumen de asignación" cuando NO se activan OKR
// individuales: el Objective de sucursal aplica a todos todos, no solo a los
// que alcanzó a mostrar el dropdown de búsqueda.
const branchEmployeeCount = ref(0)
let employeeSearchTimer: ReturnType<typeof setTimeout> | null = null
async function loadEmployees(search = '') {
    if (!form.branch_id) { employeeOptions.value = []; branchEmployeeCount.value = 0; return }
    const params = new URLSearchParams({ branch_id: String(form.branch_id) })
    if (search) params.set('search', search)
    const res = await fetch(`/okr/employees-lookup?${params.toString()}`, { headers: { Accept: 'application/json' } })
    const data = res.ok ? await res.json() : { employees: [], total_in_branch: 0 }
    employeeOptions.value = data.employees ?? []
    branchEmployeeCount.value = data.total_in_branch ?? 0
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
// Paso 4 — nombre del responsable elegido, para el resumen detallado final.
const selectedResponsibleName = computed(() => responsibleOptions.value.find((u) => u.id === form.responsible_user_id)?.full_name ?? '—')
// Si "Agregar OKR individuales por vendedor" está apagado, el Objective de
// sucursal aplica a TODOS los colaboradores de esa sucursal — el contador
// debe reflejar eso (branchEmployeeCount), no quedarse en 0. Si está
// encendido, sigue contando solo a los colaboradores agregados uno por uno.
const summaryCollaboratorsCount = computed(() => individualsEnabled.value ? individualRows.length : branchEmployeeCount.value)

// ── Paso 2: Key Results y KPIs ──
function addKr() {
    // El peso del nuevo Key Result arranca en el % que aún falta para llegar
    // a 100 (nunca vacío) — ayuda a que la suma final cierre en 100 sin que
    // el usuario tenga que hacer la resta a mano.
    const remaining = Math.max(0, round2(100 - totalWeight.value))
    form.key_results.push({
        kpi_id: null, description: '', baseline_value: '', target_value: '',
        weight: remaining > 0 ? String(remaining) : '', baseline_mode: 'auto',
        type_filter: null, direction_filter: null,
    })
}
function removeKr(i: number) { form.key_results.splice(i, 1) }
watch(() => step.value, (s) => { if (s === 2 && form.key_results.length === 0) addKr() })

// Si queda UN SOLO Key Result, matemáticamente su peso SIEMPRE debe ser 100%
// — nunca queda a criterio del usuario ni puede quedar en otro valor. Se
// bloquea el input en el template (:disabled) y aquí se fuerza el valor.
watch(() => form.key_results.length, (len) => {
    if (len === 1) form.key_results[0].weight = '100'
}, { immediate: true })

function kpiOf(id: number | null) { return props.kpis.find((k) => k.id === id) ?? null }
const totalWeight = computed(() => form.key_results.reduce((sum, kr) => sum + (Number(kr.weight) || 0), 0))
const weightValid = computed(() => Math.abs(totalWeight.value - 100) < 0.01)

// Tope dinámico por fila: nunca se puede meter un peso que, sumado al resto
// de las filas, pase de 100% — si ya asignaste 90% en las demás, esta fila
// solo admite hasta 10%, jamás 101% aunque el usuario lo intente escribir.
function maxWeightForRow(i: number): number {
    const others = form.key_results.reduce((sum, kr, idx) => (idx === i ? sum : sum + (Number(kr.weight) || 0)), 0)
    return Math.max(0, round2(100 - others))
}
function onWeightInput(kr: KrRow, i: number, value: string) {
    if (value === '') { kr.weight = ''; return }
    const max = maxWeightForRow(i)
    const clamped = Math.min(Math.max(Number(value) || 0, 0), max)
    // No reformatear mientras el usuario sigue tecleando un decimal (ej. "10.")
    kr.weight = value.endsWith('.') && clamped === Number(value.slice(0, -1)) ? value : String(clamped)
}

// ── Filtro Tipo/Dirección → acota qué KPI puede elegirse en "KPI relacionado" ──
// Tipo y Dirección siguen siendo atributos del KPI del catálogo (no se
// inventan columnas nuevas en key_results) — pero aquí se vuelven selects
// reales y funcionales: cambiarlos filtra el catálogo a los KPI compatibles,
// y si el KPI ya elegido deja de calzar, se limpia para que el usuario
// escoja uno nuevo que sí cumpla lo que pidió.
//
// BUG corregido: `kr.type_filter`/`direction_filter` en null significaba DOS
// cosas a la vez — "todavía no tocaste el filtro" (usa el valor del KPI ya
// elegido) Y "elegiste 'Cualquiera' a propósito" — con `??` ambas caían al
// mismo `null` y el fallback al KPI se comía la elección de "Cualquiera": no
// había forma de volver a "sin filtro" una vez que ya había un KPI elegido.
// Ahora "Cualquiera" se guarda como el sentinel ALL_FILTER (NO como null),
// así se distingue de "sin tocar todavía" y sí se respeta al reabrir el select.
function typeFilterOf(kr: KrRow): string | null {
    if (kr.type_filter === ALL_FILTER) return null
    return kr.type_filter ?? kpiOf(kr.kpi_id)?.type ?? null
}
function directionFilterOf(kr: KrRow): string | null {
    if (kr.direction_filter === ALL_FILTER) return null
    return kr.direction_filter ?? kpiOf(kr.kpi_id)?.direction ?? null
}
function kpisMatchingFilters(kr: KrRow) {
    const type = typeFilterOf(kr)
    const direction = directionFilterOf(kr)
    return props.kpis.filter((k) => (!type || k.type === type) && (!direction || k.direction === direction))
}
function onTypeFilterChange(kr: KrRow, value: string) {
    kr.type_filter = value
    if (kr.kpi_id && !kpisMatchingFilters(kr).some((k) => k.id === kr.kpi_id)) kr.kpi_id = null
}
function onDirectionFilterChange(kr: KrRow, value: string) {
    kr.direction_filter = value
    if (kr.kpi_id && !kpisMatchingFilters(kr).some((k) => k.id === kr.kpi_id)) kr.kpi_id = null
}
function onKpiPick(kr: KrRow, id: number | null) {
    kr.kpi_id = id
    kr.type_filter = null
    kr.direction_filter = null
}

// ── Reordenar Key Results arrastrando el "⠿" (docs/imagenesOKR/5.png) ──
const dragIndex = ref<number | null>(null)
function onDragStart(i: number) { dragIndex.value = i }
function onDropRow(i: number) {
    if (dragIndex.value === null || dragIndex.value === i) return
    const [moved] = form.key_results.splice(dragIndex.value, 1)
    form.key_results.splice(i, 0, moved)
    dragIndex.value = null
}

// FIX: ApexCharts, si no se le apaga explícitamente el dataLabel "name" de
// radialBar, muestra el nombre de la serie ("series-1" por defecto al no
// pasar `series` con nombre) encima del valor — por eso se veía "series-1"
// en vez de solo el porcentaje grande y centrado que pide la referencia
// (docs/imagenesOKR/5.png y 6.png).
const donutOptions = computed(() => ({
    chart: { sparkline: { enabled: true } },
    colors: [weightValid.value ? '#10b981' : '#f59e0b'],
    plotOptions: {
        radialBar: {
            hollow: { size: '66%' },
            track: { background: 'rgba(148,163,184,0.18)' },
            dataLabels: {
                name: { show: false },
                value: { offsetY: 8, fontSize: '26px', fontWeight: 700, formatter: () => `${Math.round(totalWeight.value)}%` },
            },
        },
    },
    stroke: { lineCap: 'round' },
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
// Serie que SÍ se grafica — % de avance esperado (0-100), igual que el eje Y
// de la referencia (docs/imagenesOKR/7.png y 8.png: 10%, 20%... hasta 100%).
// Como la trayectoria es una interpolación lineal base→meta, el % esperado en
// la semana N es simplemente N/total — la misma matemática que ya usa
// OkrTrajectoryService, solo expresada en porcentaje en vez de valor crudo
// del KPI (que puede estar en pesos, cientos, etc. y no cabría en un eje 0-100).
const trajectoryPercents = computed(() => {
    if (trajectoryPoints.value.length === 0) return []
    const weeks = Math.max(1, form.duration_weeks)
    return Array.from({ length: weeks }, (_, i) => Math.round(((i + 1) / weeks) * 100))
})
const expectedAtWeek1Pct = computed(() => (form.duration_weeks > 0 ? Math.round((1 / form.duration_weeks) * 100) : 0))

const endDatePreview = computed(() => {
    const start = new Date(`${form.start_date}T00:00:00`)
    if (Number.isNaN(start.getTime())) return null
    start.setDate(start.getDate() + form.duration_weeks * 7 - 1)
    return start.toISOString().slice(0, 10)
})

// Opciones fijas de plazo (semanas) para el select "Plazo de evaluación" —
// siempre incluye el valor actual aunque no esté en la lista común, para no
// perder un valor ya capturado (ej. si venía de un objective ya editado).
const weekOptionsList = computed(() => {
    const base = [4, 6, 8, 10, 12, 16, 20, 24, 36, 48, 52]
    if (!base.includes(form.duration_weeks)) base.push(form.duration_weeks)
    return base.sort((a, b) => a - b)
})

const trajectoryChartOptions = computed(() => {
    const showDataLabels = trajectoryPercents.value.length > 0 && trajectoryPercents.value.length <= 14
    return {
        chart: { toolbar: { show: false }, sparkline: { enabled: false } },
        xaxis: {
            categories: trajectoryPercents.value.map((_, i) => `S${i + 1}`),
            labels: { style: { fontSize: '10px' } },
            axisTicks: { show: false },
        },
        // El eje Y muestra el % de avance en pasos de 10 — 0%, 10%, 20%... 100%
        // (antes venía oculto: `labels: { show: false }`, ese era el bug). Con
        // 11 etiquetas (0 a 100) se ven apretadas si la gráfica es baja — por
        // eso subimos su alto (ver :height más abajo) y agrandamos la fuente.
        yaxis: {
            min: 0, max: 100, tickAmount: 10,
            labels: { formatter: (v: number) => `${Math.round(v)}%`, style: { fontSize: '11px', colors: ['#94a3b8'] }, offsetX: -4 },
        },
        colors: ['#4f46e5'],
        stroke: { curve: 'straight', width: 2.5 },
        grid: { borderColor: 'rgba(148,163,184,0.25)', strokeDashArray: 4, padding: { left: 8, right: 8, top: 4 } },
        // Punto visible donde cada semana (eje X) se cruza con su % (eje Y),
        // con un hover más grande para resaltar el punto bajo el cursor.
        markers: { size: 4, colors: ['#ffffff'], strokeColors: '#4f46e5', strokeWidth: 2, hover: { size: 6 } },
        dataLabels: {
            enabled: showDataLabels,
            formatter: (v: number) => `${v}%`,
            offsetY: -10,
            style: { fontSize: '9px', fontWeight: 700, colors: ['#4f46e5'] },
        },
        tooltip: {
            y: {
                // Además del %, se muestra el valor crudo esperado del KPI en
                // esa semana (ej. "40% · $130,000.00") — mismo dato que ya
                // calcula trajectoryPoints, solo presentado junto al %.
                formatter: (val: number, opts: any) => {
                    const raw = trajectoryPoints.value[opts?.dataPointIndex]
                    const unit = kpiOf(primaryKr.value?.kpi_id ?? null)?.unit
                    return raw !== undefined ? `${val}% · ${formatByUnit(raw, unit)}` : `${val}%`
                },
            },
        },
    }
})

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
        <DialogContent
            class="flex max-h-[90vh] w-[95vw] max-w-[95vw] sm:max-w-[1400px] flex-col gap-0 overflow-hidden p-0"
            :show-close-button="false"
            @pointer-down-outside="(e) => e.preventDefault()"
            @interact-outside="(e) => e.preventDefault()"
        >
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
                                <div class="flex-1">
                                    <SearchableSelect
                                        v-model="addEmployeeId as any"
                                        placeholder="Agregar colaborador"
                                        search-placeholder="Buscar colaborador..."
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
                            <div><p class="text-xs text-muted-foreground">Colaboradores</p><p class="font-semibold text-foreground">{{ summaryCollaboratorsCount }}</p></div>
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
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><Sparkles class="size-4" /></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Objetivo seleccionado</p>
                                <p class="truncate text-sm font-semibold text-foreground">{{ form.title || 'Objective sin título' }}</p>
                                <p class="truncate text-xs text-muted-foreground">{{ selectedBranchName }}</p>
                            </div>
                        </div>

                        <div>
                            <p class="text-sm font-semibold text-foreground">Resultados clave a medir</p>
                            <p class="text-xs text-muted-foreground">Define los resultados clave que impulsarán el cumplimiento del objetivo y los KPI asociados.</p>
                        </div>

                        <div class="overflow-x-auto rounded-xl border border-border">
                            <table class="w-full min-w-[760px] text-xs">
                                <thead class="border-b border-border bg-muted/30 text-muted-foreground">
                                    <tr>
                                        <th class="w-10 px-2 py-2"></th>
                                        <th class="px-2 py-2 text-left font-semibold">Resultado clave</th>
                                        <th class="min-w-[150px] px-2 py-2 text-left font-semibold">KPI relacionado</th>
                                        <th class="min-w-[130px] px-2 py-2 text-left font-semibold">Tipo de KPI</th>
                                        <th class="min-w-[130px] px-2 py-2 text-left font-semibold">Dirección</th>
                                        <th class="w-24 px-2 py-2 text-left font-semibold">Peso</th>
                                        <th class="w-8 px-2 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(kr, i) in form.key_results" :key="i"
                                        class="border-t border-border transition"
                                        :class="dragIndex === i ? 'opacity-40' : ''"
                                        draggable="true"
                                        @dragstart="onDragStart(i)"
                                        @dragover.prevent
                                        @drop="onDropRow(i)"
                                    >
                                        <td class="px-2 py-2.5 text-center text-muted-foreground">
                                            <span class="inline-flex items-center gap-1">
                                                <GripVertical class="size-3.5 cursor-grab text-muted-foreground/70 hover:text-foreground" />
                                                {{ i + 1 }}
                                            </span>
                                        </td>
                                        <td class="min-w-[180px] px-2 py-2.5">
                                            <input v-model="kr.description" placeholder="Incrementar colocación de cartera" class="app-input h-9 text-xs">
                                        </td>

                                        <!-- KPI relacionado — pill azul, filtrado por Tipo/Dirección si el usuario los usó -->
                                        <td class="px-2 py-2.5">
                                            <Select :model-value="kr.kpi_id !== null ? String(kr.kpi_id) : undefined" @update:model-value="(v) => onKpiPick(kr, v ? Number(v) : null)">
                                                <SelectTrigger size="sm" class="w-full justify-between gap-1 rounded-full border-0 bg-blue-500/10 px-3 text-[11px] font-semibold text-blue-700 hover:bg-blue-500/15 dark:text-blue-300">
                                                    <SelectValue placeholder="Selecciona un KPI" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem v-for="k in kpisMatchingFilters(kr)" :key="k.id" :value="String(k.id)">{{ k.name }}</SelectItem>
                                                    <p v-if="kpisMatchingFilters(kr).length === 0" class="px-2 py-3 text-center text-[11px] text-muted-foreground">Ningún KPI coincide con Tipo/Dirección.</p>
                                                </SelectContent>
                                            </Select>
                                        </td>

                                        <!-- Tipo de KPI — pill violeta, editable: filtra el catálogo -->
                                        <td class="px-2 py-2.5">
                                            <Select :model-value="typeFilterOf(kr) ?? ALL_FILTER" @update:model-value="(v) => onTypeFilterChange(kr, v as string)">
                                                <SelectTrigger size="sm" class="w-full justify-between gap-1 rounded-full border-0 bg-violet-500/10 px-3 text-[11px] font-semibold text-violet-700 hover:bg-violet-500/15 dark:text-violet-300">
                                                    <SelectValue placeholder="Tipo" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem :value="ALL_FILTER">Cualquiera</SelectItem>
                                                    <SelectItem value="cumulative">Acumulativo</SelectItem>
                                                    <SelectItem value="balance">Saldo</SelectItem>
                                                    <SelectItem value="percentage">Porcentual</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </td>

                                        <!-- Dirección — pill verde/ámbar según incrementar/disminuir, editable: filtra el catálogo -->
                                        <td class="px-2 py-2.5">
                                            <Select :model-value="directionFilterOf(kr) ?? ALL_FILTER" @update:model-value="(v) => onDirectionFilterChange(kr, v as string)">
                                                <SelectTrigger
                                                    size="sm" class="w-full justify-between gap-1 rounded-full border-0 px-3 text-[11px] font-semibold hover:opacity-80"
                                                    :class="directionFilterOf(kr) === 'decrease' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'"
                                                >
                                                    <SelectValue placeholder="Dirección" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem :value="ALL_FILTER">Cualquiera</SelectItem>
                                                    <SelectItem value="increase">Incrementar ↗</SelectItem>
                                                    <SelectItem value="decrease">Disminuir ↘</SelectItem>
                                                </SelectContent>
                                            </Select>
                                        </td>

                                        <td class="px-2 py-2.5">
                                            <input
                                                :value="kr.weight" type="number" step="0.01" min="0"
                                                :max="maxWeightForRow(i)"
                                                :disabled="form.key_results.length === 1"
                                                placeholder="0" class="app-input h-9 text-xs disabled:cursor-not-allowed disabled:opacity-70"
                                                @input="(e) => onWeightInput(kr, i, (e.target as HTMLInputElement).value)"
                                            >
                                        </td>
                                        <td class="px-2 py-2.5 text-center">
                                            <button v-if="form.key_results.length > 1" type="button" class="text-muted-foreground transition hover:text-destructive" @click="removeKr(i)"><Trash2 class="size-3.5" /></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <p class="text-[11px] text-muted-foreground">
                            Arrastra <GripVertical class="-mt-0.5 inline size-3 align-middle" /> para reordenar. Un solo Key Result siempre pesa 100%; con varios, la suma nunca puede pasar de 100%.
                        </p>

                        <Button type="button" variant="outline" size="sm" class="gap-1.5 rounded-xl border-dashed" @click="addKr">
                            <Plus class="size-3.5" /> Agregar Key Result
                        </Button>
                    </div>

                    <aside class="app-card h-fit space-y-4 p-4 text-center">
                        <p class="text-xs font-bold uppercase tracking-wide text-muted-foreground">Resumen del OKR</p>
                        <div class="mx-auto w-32"><VueApexCharts type="radialBar" :height="128" :options="donutOptions" :series="[Math.min(100, totalWeight)]" /></div>
                        <p class="-mt-2 text-xs text-muted-foreground">Pesos asignados</p>

                        <div class="flex items-center gap-2.5 rounded-xl border border-border bg-muted/20 p-3 text-left">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><ListChecks class="size-4" /></span>
                            <p class="text-sm font-semibold text-foreground">{{ form.key_results.length }} KPI seleccionados</p>
                        </div>

                        <div class="flex items-start gap-2 rounded-xl bg-muted/30 p-3 text-left text-[11px] text-muted-foreground">
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            <span>El cumplimiento del OKR se calcula por ponderación.</span>
                        </div>
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
                                    <td class="px-2 py-2.5 font-medium text-foreground">
                                        <span class="flex items-center gap-2">
                                            <span class="flex size-6 shrink-0 items-center justify-center rounded-full" :class="kpiIconFor(kpiOf(kr.kpi_id)).class">
                                                <component :is="kpiIconFor(kpiOf(kr.kpi_id)).icon" class="size-3.5" />
                                            </span>
                                            {{ kpiOf(kr.kpi_id)?.name ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-2.5">
                                        <span class="rounded-full bg-violet-500/10 px-2 py-0.5 text-[10px] font-semibold text-violet-700 dark:text-violet-300">{{ kpiTypeLabel(kpiOf(kr.kpi_id)?.type) }}</span>
                                    </td>
                                    <td class="px-2 py-2.5">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="kpiOf(kr.kpi_id)?.direction === 'decrease' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'"
                                        >
                                            {{ kpiOf(kr.kpi_id) ? `${kpiDirectionLabel(kpiOf(kr.kpi_id)?.direction)} ${kpiOf(kr.kpi_id)?.direction === 'decrease' ? '↘' : '↗'}` : '—' }}
                                        </span>
                                    </td>
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
                        <Info class="mt-0.5 size-3.5 shrink-0" />
                        <span>Fórmula de cálculo de la meta: <strong class="text-foreground">Meta = Base congelada + Incremento esperado</strong>. Para KPI porcentuales, el incremento se expresa en puntos porcentuales (pp).</span>
                    </p>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                        <div class="app-card space-y-2 p-4">
                            <p class="text-xs font-semibold text-foreground">Plazo de evaluación</p>
                            <p class="text-[11px] text-muted-foreground">Define el horizonte temporal para medir el avance del OKR.</p>

                            <div class="flex items-center gap-2 pt-1">
                                <CalendarDays class="size-4 shrink-0 text-primary" />
                                <Select :model-value="String(form.duration_weeks)" @update:model-value="(v) => (form.duration_weeks = Number(v))">
                                    <SelectTrigger size="sm" class="h-9 w-full justify-between gap-1 rounded-lg border-0 bg-muted/60 px-3 text-sm font-bold text-foreground">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="w in weekOptionsList" :key="w" :value="String(w)">{{ w }} semanas</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div class="flex flex-col gap-1 pt-2 text-[11px]">
                                <p><span class="font-semibold text-foreground">Inicio:</span> <span class="text-muted-foreground">{{ formatFriendlyDate(form.start_date) }}</span></p>
                                <p><span class="font-semibold text-foreground">Término:</span> <span class="text-muted-foreground">{{ endDatePreview ? formatFriendlyDate(endDatePreview) : '—' }}</span></p>
                            </div>
                        </div>
                        <div class="app-card space-y-2 p-4">
                            <p class="text-xs font-semibold text-foreground">Trayectoria esperada</p>
                            <p class="text-[11px] text-muted-foreground">Proyección del avance acumulado durante el periodo.</p>
                            <VueApexCharts v-if="trajectoryPercents.length > 1" type="line" :height="190" :options="trajectoryChartOptions" :series="[{ name: 'Avance esperado', data: trajectoryPercents }]" />
                            <p v-else class="py-6 text-center text-[11px] text-muted-foreground">Captura base y meta para ver la trayectoria.</p>
                        </div>
                        <div class="app-card space-y-2 p-4">
                            <p class="text-xs font-semibold text-foreground">Avance esperado vs. meta final</p>
                            <p class="text-[11px] text-muted-foreground">Comparación del avance esperado al cierre frente a la meta.</p>
                            <div class="mt-2 grid grid-cols-2 gap-2 text-center">
                                <div class="rounded-lg bg-muted/30 p-2"><p class="text-lg font-bold text-foreground">{{ expectedAtWeek1Pct }}%</p><p class="text-[10px] text-muted-foreground">Avance esperado a la fecha</p></div>
                                <div class="rounded-lg bg-primary/10 p-2"><p class="text-lg font-bold text-primary">100%</p><p class="text-[10px] text-muted-foreground">Meta final</p></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PASO 4: Resumen y confirmación -->
                <div v-else-if="step === 4" class="space-y-5">
                    <!-- Objective: encabezado + 4 datos clave con ícono, cada uno en su propia tarjeta -->
                    <div class="app-card space-y-3 p-4">
                        <div class="flex items-start gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><Sparkles class="size-5" /></span>
                            <div class="min-w-0">
                                <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Objective a crear</p>
                                <p class="text-sm font-semibold text-foreground">{{ form.title }}</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5 pt-1 sm:grid-cols-4">
                            <div class="flex items-center gap-2 rounded-xl border border-border bg-muted/20 p-2.5 transition hover:border-primary/40 hover:bg-primary/5">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-500/10 text-blue-600 dark:text-blue-300"><Building2 class="size-4" /></span>
                                <div class="min-w-0"><p class="text-[10px] text-muted-foreground">Sucursal</p><p class="truncate text-xs font-semibold text-foreground">{{ selectedBranchName }}</p></div>
                            </div>
                            <div class="flex items-center gap-2 rounded-xl border border-border bg-muted/20 p-2.5 transition hover:border-primary/40 hover:bg-primary/5">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-violet-500/10 text-violet-600 dark:text-violet-300"><UserCheck class="size-4" /></span>
                                <div class="min-w-0"><p class="text-[10px] text-muted-foreground">Responsable</p><p class="truncate text-xs font-semibold text-foreground">{{ selectedResponsibleName }}</p></div>
                            </div>
                            <div class="flex items-center gap-2 rounded-xl border border-border bg-muted/20 p-2.5 transition hover:border-primary/40 hover:bg-primary/5">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-500/10 text-amber-600 dark:text-amber-300"><CalendarDays class="size-4" /></span>
                                <div class="min-w-0"><p class="text-[10px] text-muted-foreground">Plazo</p><p class="truncate text-xs font-semibold text-foreground">{{ form.duration_weeks }} semanas</p></div>
                            </div>
                            <div class="flex items-center gap-2 rounded-xl border border-border bg-muted/20 p-2.5 transition hover:border-primary/40 hover:bg-primary/5">
                                <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"><Users2 class="size-4" /></span>
                                <div class="min-w-0"><p class="text-[10px] text-muted-foreground">Tipo</p><p class="truncate text-xs font-semibold text-foreground">{{ assignmentType }}</p></div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 rounded-xl bg-muted/30 px-3 py-2 text-[11px] text-muted-foreground">
                            <CalendarDays class="size-3.5 shrink-0" />
                            <span><strong class="font-semibold text-foreground">{{ formatFriendlyDate(form.start_date) }}</strong> → <strong class="font-semibold text-foreground">{{ endDatePreview ? formatFriendlyDate(endDatePreview) : '—' }}</strong></span>
                        </div>
                    </div>

                    <!-- OKR individuales — solo si se activó el switch y hay colaboradores agregados -->
                    <div v-if="individualsEnabled && individualRows.length" class="app-card space-y-2.5 p-4">
                        <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                            <Users2 class="size-3.5 text-primary" /> OKR individuales ({{ individualRows.length }})
                        </p>
                        <div
                            v-for="row in individualRows" :key="row.employee_id"
                            class="flex items-start gap-2.5 rounded-xl border border-border bg-muted/20 p-2.5 text-xs transition hover:border-primary/40 hover:bg-primary/5"
                        >
                            <span class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary">{{ row.employee_name.slice(0, 2).toUpperCase() }}</span>
                            <div class="min-w-0">
                                <p class="font-semibold text-foreground">{{ row.employee_name }}</p>
                                <p class="text-muted-foreground">{{ row.title }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Key Results — mismo ícono/color por KPI que en el Paso 3, con barra de peso -->
                    <div class="app-card space-y-2.5 p-4">
                        <div class="flex items-center justify-between">
                            <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-muted-foreground">
                                <ListChecks class="size-3.5 text-primary" /> Key Results ({{ totalWeight.toFixed(2) }}% ponderado)
                            </p>
                            <span class="flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="weightValid ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-destructive/10 text-destructive'">
                                <CheckCircle2 v-if="weightValid" class="size-3" /><AlertTriangle v-else class="size-3" />
                                {{ weightValid ? 'Listo para guardar' : 'Los pesos deben sumar 100%' }}
                            </span>
                        </div>

                        <div
                            v-for="(kr, i) in form.key_results" :key="i"
                            class="space-y-2 rounded-xl border border-border bg-muted/20 p-3 text-xs transition hover:border-primary/40 hover:bg-primary/5"
                        >
                            <div class="flex items-start gap-2.5">
                                <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full" :class="kpiIconFor(kpiOf(kr.kpi_id)).class">
                                    <component :is="kpiIconFor(kpiOf(kr.kpi_id)).icon" class="size-4" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold text-foreground">{{ kr.description }} <span class="text-muted-foreground">— {{ kpiOf(kr.kpi_id)?.name }}</span></p>
                                    <p class="mt-0.5 text-muted-foreground">Base {{ kr.baseline_value || '(auto)' }} → Meta {{ kr.target_value }}</p>
                                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-full bg-violet-500/10 px-2 py-0.5 text-[10px] font-semibold text-violet-700 dark:text-violet-300">{{ kpiTypeLabel(kpiOf(kr.kpi_id)?.type) }}</span>
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[10px] font-semibold"
                                            :class="kpiOf(kr.kpi_id)?.direction === 'decrease' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'"
                                        >
                                            {{ kpiDirectionLabel(kpiOf(kr.kpi_id)?.direction) }} {{ kpiOf(kr.kpi_id)?.direction === 'decrease' ? '↘' : '↗' }}
                                        </span>
                                    </div>
                                </div>
                                <span class="shrink-0 text-sm font-bold tabular-nums text-foreground">{{ kr.weight }}%</span>
                            </div>
                            <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                                <div class="h-1.5 rounded-full bg-primary transition-all" :style="{ width: `${Math.min(100, Number(kr.weight) || 0)}%` }" />
                            </div>
                        </div>
                    </div>

                    <p class="flex items-center gap-2 rounded-xl bg-primary/5 px-3 py-2.5 text-xs font-medium text-primary">
                        <Info class="size-4 shrink-0" /> El OKR se guardará en BORRADOR. Podrás revisar la línea base y activarlo desde el detalle.
                    </p>
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
