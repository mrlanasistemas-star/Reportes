<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import {
    AlertTriangle,
    ArrowRightLeft,
    Building2,
    CheckCircle2,
    ClipboardList,
    FileText,
    GitCompareArrows,
    PenSquare,
    Search,
    Sparkles,
    UserMinus,
    UserPlus,
    UserRound,
    Wand2,
    X,
} from 'lucide-vue-next'
import Swal from 'sweetalert2'

import AppLayout from '@/layouts/AppLayout.vue'
import ChartCard from '@/components/radiography/ChartCard.vue'
import SelectField from '@/components/forms/SelectField.vue'

type AssignmentAlias = {
    employee_id: number
    employee_name: string
    branch_name?: string | null
    branch_id?: number | null
    match_type?: string | null
}

type Assignment = {
    id: number
    employee_id?: number | null
    branch_id?: number | null
    employee_name: string
    normalized_name?: string | null
    branch_name?: string | null
    source_name?: string | null
    source_reference?: string | null
    match_type?: 'exact' | 'normalized' | 'manual' | 'unmatched' | string | null
    match_label?: string | null
    match_explanation?: string | null
    confidence?: number | null
    was_manual_reviewed?: boolean
    ui_status: 'matched' | 'pending' | 'manual' | 'unmatched'
    period_label?: string | null
    updated_at?: string | null
    notes?: string | null
    needs_manual_attention?: boolean
    context?: string
    aliases?: AssignmentAlias[]
}

// Altas/bajas del periodo (cierre 17-sep-2026, ronda 4) — vienen de
// `period_employee_rosters` (roster canónico, deduplicado por persona real,
// MISMA fuente que el Índice de Rotación de OKR), no de `Assignment` — forma
// más chica a propósito, nunca confundir con una asignación real.
type RosterMovementItem = {
    id: number
    employee_id: number
    employee_name: string
    branch_name?: string | null
    period_label?: string | null
}

type Branch = {
    id: number
    name: string
}

type PeriodOption = {
    id: number
    label: string
    type?: string | null
    start_date?: string | null
    end_date?: string | null
}

const props = withDefaults(
    defineProps<{
        assignments: Assignment[]
        branches: Branch[]
        periods: PeriodOption[]
        selected_period_id?: number | null
        selected_period_label?: string | null
        summary?: {
            total: number
            matched: number
            manual: number
            pending: number
            unmatched: number
            with_branch: number
            without_branch: number
            high_confidence: number
            needs_review: number
            hires: number
            leavers: number
            plantilla: number
            roster_calculado: boolean
        }
        incidences?: Assignment[]
        hires?: RosterMovementItem[]
        leavers?: RosterMovementItem[]
    }>(),
    {
        assignments: () => [],
        branches: () => [],
        periods: () => [],
        selected_period_id: null,
        selected_period_label: null,
        summary: () => ({
            total: 0,
            matched: 0,
            manual: 0,
            pending: 0,
            unmatched: 0,
            with_branch: 0,
            without_branch: 0,
            high_confidence: 0,
            needs_review: 0,
            hires: 0,
            leavers: 0,
            plantilla: 0,
            roster_calculado: false,
        }),
        incidences: () => [],
        hires: () => [],
        leavers: () => [],
    },
)

defineOptions({
    layout: AppLayout,
})

const filters = reactive({
    query: '',
    status: 'all',
    branch: '',
})

// Filtro por sucursal (cierre 17-sep-2026, ronda 5) — opciones a partir de las
// sucursales que REALMENTE aparecen en las asignaciones de este periodo, nunca
// el catálogo completo (evita mostrar sucursales sin ningún colaborador aquí).
const branchFilterOptions = computed(() => {
    const names = new Set(props.assignments.map((a) => a.branch_name).filter((n): n is string => !!n))
    return [
        { value: '', label: 'Todas las sucursales' },
        ...Array.from(names).sort().map((n) => ({ value: n, label: n })),
    ]
})

// Distribución de colaboradores por sucursal — gráfica pequeña, solo informativa
// (nunca reemplaza los KPIs de arriba, que son la fuente real de conteos).
const branchDistribution = computed(() => {
    const counts = new Map<string, number>()
    for (const a of props.assignments) {
        const key = a.branch_name || 'Sin sucursal'
        counts.set(key, (counts.get(key) ?? 0) + 1)
    }
    return Array.from(counts.entries()).sort((a, b) => b[1] - a[1]).slice(0, 10)
})
const branchChartOptions = computed(() => ({
    chart: { toolbar: { show: false } },
    colors: ['#0ea5e9'],
    plotOptions: { bar: { borderRadius: 5, horizontal: true, barHeight: '55%' } },
    dataLabels: { enabled: false },
    xaxis: { categories: branchDistribution.value.map(([name]) => name), labels: { style: { fontSize: '10px' } } },
    grid: { borderColor: '#f1f5f9' },
}))
const branchChartSeries = computed(() => [{ name: 'Colaboradores', data: branchDistribution.value.map(([, count]) => count) }])

const selectedPeriodId = computed({
    get: () => (props.selected_period_id ? String(props.selected_period_id) : ''),
    set: (value: string) => {
        router.get(
            '/asignaciones-empleado-sucursal',
            { period_id: value || undefined },
            { preserveScroll: true, preserveState: true, replace: true },
        )
    },
})

// Auto-match — only used via the empty-state "Inicializar" button
const autoMatchForm = useForm({
    period_id: props.selected_period_id ?? null,
})

function runAutoMatch() {
    autoMatchForm.period_id = props.selected_period_id ?? null
    autoMatchForm.post('/asignaciones-empleado-sucursal/match-automatico', {
        preserveScroll: true,
    })
}

// Per-employee assignment modal
const modal = reactive({
    open: false,
    saving: false,
    employeeId: null as number | null,
    assignmentId: null as number | null,
    employeeName: '',
    branchQuery: '',
    branch_id: '',
    notes: '',
})

function openAssignModal(item: Assignment) {
    modal.open = true
    modal.saving = false
    modal.employeeId = item.employee_id ?? null
    modal.assignmentId = item.id
    modal.employeeName = item.employee_name
    modal.branchQuery = ''
    modal.branch_id = item.branch_id ? String(item.branch_id) : ''
    modal.notes = item.notes ?? ''
}

function closeModal() {
    modal.open = false
    modal.saving = false
}

const modalBranches = computed(() => {
    const q = modal.branchQuery.trim().toLowerCase()
    if (!q) return props.branches
    return props.branches.filter((b) => b.name.toLowerCase().includes(q))
})

async function saveModal() {
    if (!modal.branch_id) {
        await Swal.fire({
            title: 'Selecciona una sucursal',
            icon: 'warning',
            confirmButtonText: 'Entendido',
        })
        return
    }

    modal.saving = true

    if (modal.employeeId && props.selected_period_id) {
        router.post(
            `/empleados/${modal.employeeId}/asignar-sucursal`,
            {
                branch_id: modal.branch_id,
                period_id: props.selected_period_id,
                notes: modal.notes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => closeModal(),
                onFinish: () => {
                    modal.saving = false
                },
            },
        )
    } else {
        router.post(
            `/asignaciones-empleado-sucursal/${modal.assignmentId}/match-manual`,
            {
                branch_id: modal.branch_id,
                notes: modal.notes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => closeModal(),
                onFinish: () => {
                    modal.saving = false
                },
            },
        )
    }
}

function statusClass(status: Assignment['ui_status']) {
    switch (status) {
        case 'matched':
            return 'border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300'
        case 'manual':
            return 'border border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300'
        case 'unmatched':
            return 'border border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300'
        default:
            return 'border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300'
    }
}

function statusLabel(status: Assignment['ui_status']) {
    switch (status) {
        case 'matched':
            return 'Match correcto'
        case 'manual':
            return 'Manual'
        case 'unmatched':
            return 'Sin match'
        default:
            return 'Pendiente'
    }
}

function formatConfidence(value?: number | null) {
    if (value === null || value === undefined) return '—'
    return `${Math.round(value * 100)}%`
}

const filteredAssignments = computed(() => {
    const query = filters.query.trim().toLowerCase()

    return props.assignments.filter((item) => {
        const aliasNames = (item.aliases ?? []).map((a) => a.employee_name.toLowerCase()).join(' ')
        const matchesQuery =
            !query ||
            item.employee_name.toLowerCase().includes(query) ||
            (item.normalized_name ?? '').toLowerCase().includes(query) ||
            (item.branch_name ?? '').toLowerCase().includes(query) ||
            (item.notes ?? '').toLowerCase().includes(query) ||
            aliasNames.includes(query)

        const matchesStatus = filters.status === 'all' || item.ui_status === filters.status
        const matchesBranch = !filters.branch || item.branch_name === filters.branch

        return matchesQuery && matchesStatus && matchesBranch
    })
})

const matchedAssignments = computed(() => filteredAssignments.value.filter((item) => item.ui_status === 'matched'))
const manualAssignments = computed(() => filteredAssignments.value.filter((item) => item.ui_status === 'manual'))
const pendingAssignments = computed(() =>
    filteredAssignments.value.filter(
        (item) => ['pending', 'unmatched'].includes(item.ui_status) || item.needs_manual_attention,
    ),
)

const hasNoAssignments = computed(() => props.assignments.length === 0)
</script>

<template>
    <Head title="Colaboradores" />

    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50/40 p-4 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-screen-2xl space-y-6">

            <!-- Header -->
            <section class="overflow-hidden rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-300 sm:p-8">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 items-center justify-center rounded-2xl bg-sky-500">
                        <GitCompareArrows class="size-5 text-white" />
                    </div>
                    <p class="text-xs font-black uppercase tracking-[0.28em] text-sky-300">Colaboradores</p>
                </div>
                <h1 class="mt-3 text-3xl font-black tracking-tight sm:text-4xl">Colaboradores por sucursal y periodo</h1>
                <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">
                    Revisa en qué sucursal está cada colaborador este periodo, por qué uno no tiene sucursal
                    asignada todavía, y quién entró o salió respecto al periodo anterior. Usa el botón
                    <span class="font-bold text-sky-300">Asignar sucursal</span> en cada tarjeta para corregir o confirmar manualmente.
                </p>
                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <select
                        v-model="selectedPeriodId"
                        class="h-11 min-w-[240px] rounded-2xl border border-white/20 bg-white/10 px-4 text-sm text-white focus:outline-none focus:ring-2 focus:ring-white/30"
                    >
                        <option value="" class="text-slate-900">Selecciona un periodo</option>
                        <option
                            v-for="period in periods"
                            :key="period.id"
                            :value="String(period.id)"
                            class="text-slate-900"
                        >
                            {{ period.label }}
                        </option>
                    </select>
                </div>
                <p v-if="selected_period_label" class="mt-3 text-xs text-slate-400">
                    Periodo activo: <span class="font-bold text-white">{{ selected_period_label }}</span>
                </p>
            </section>

            <!-- Stats -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                <div
                    v-for="stat in [
                        { icon: UserRound, label: 'Activos', value: summary.total, bg: 'bg-slate-100', fg: 'text-slate-600' },
                        { icon: CheckCircle2, label: 'Con sucursal', value: summary.with_branch, bg: 'bg-emerald-50', fg: 'text-emerald-600' },
                        { icon: AlertTriangle, label: 'Incidencias', value: summary.needs_review, bg: 'bg-amber-50', fg: 'text-amber-600' },
                        { icon: Sparkles, label: 'Manuales', value: summary.manual, bg: 'bg-sky-50', fg: 'text-sky-600' },
                        { icon: UserPlus, label: 'Altas', value: summary.hires, bg: 'bg-emerald-50', fg: 'text-emerald-600' },
                        { icon: UserMinus, label: 'Bajas', value: summary.leavers, bg: 'bg-rose-50', fg: 'text-rose-600' },
                    ]"
                    :key="stat.label"
                    class="rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md"
                >
                    <div :class="['flex size-8 items-center justify-center rounded-xl', stat.bg]">
                        <component :is="stat.icon" :class="['size-4', stat.fg]" />
                    </div>
                    <p class="mt-2.5 text-[11px] font-bold tracking-wide text-slate-500 uppercase">{{ stat.label }}</p>
                    <p class="mt-0.5 text-2xl font-black text-slate-950">{{ stat.value }}</p>
                </div>
            </div>

            <!-- Main content -->
            <section class="grid gap-6 xl:grid-cols-3">
                <!-- Summary sidebar -->
                <div class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <h2 class="text-lg font-bold tracking-tight">Resumen rápido</h2>
                        <p class="mt-1 text-sm text-muted-foreground">Estado de las asignaciones del periodo.</p>
                    </div>

                    <div class="space-y-3 p-4 sm:p-5">
                        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4 dark:border-emerald-500/20 dark:bg-emerald-500/10">
                            <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Match correcto</p>
                            <p class="mt-2 text-2xl font-extrabold text-emerald-800 dark:text-emerald-200">{{ matchedAssignments.length }}</p>
                        </div>

                        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-4 dark:border-amber-500/20 dark:bg-amber-500/10">
                            <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300">Pendientes / incidencias</p>
                            <p class="mt-2 text-2xl font-extrabold text-amber-800 dark:text-amber-200">{{ pendingAssignments.length }}</p>
                        </div>

                        <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-4 dark:border-sky-500/20 dark:bg-sky-500/10">
                            <p class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-300">Ajustados manualmente</p>
                            <p class="mt-2 text-2xl font-extrabold text-sky-800 dark:text-sky-200">{{ manualAssignments.length }}</p>
                        </div>
                    </div>

                    <!-- Distribución por sucursal — informativa, chica a propósito para no
                         sobrecargar el módulo (pedido explícito: "que no sobresature"). -->
                    <div v-if="branchDistribution.length" class="border-t p-4 sm:p-5">
                        <ChartCard
                            title="Colaboradores por sucursal"
                            type="bar"
                            :height="Math.max(160, branchDistribution.length * 32)"
                            :series="branchChartSeries"
                            :options="branchChartOptions"
                        />
                    </div>
                </div>

                <!-- Employee cards -->
                <div class="app-card overflow-hidden xl:col-span-2">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                            <div>
                                <h2 class="text-lg font-bold tracking-tight">Empleados del periodo</h2>
                                <p class="mt-1 text-sm text-muted-foreground">
                                    Haz clic en <span class="font-semibold">Asignar sucursal</span> para crear o corregir la asignación de cualquier empleado.
                                </p>
                            </div>

                            <div class="flex flex-col flex-wrap gap-3 sm:flex-row">
                                <div class="relative w-full sm:w-[260px]">
                                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        v-model="filters.query"
                                        type="text"
                                        class="app-input h-11 pl-10"
                                        placeholder="Buscar empleado, sucursal o nota..."
                                    />
                                </div>

                                <select v-model="filters.status" class="app-input h-11 sm:w-[170px]">
                                    <option value="all">Todos</option>
                                    <option value="matched">Match correcto</option>
                                    <option value="manual">Manual</option>
                                    <option value="pending">Pendiente</option>
                                    <option value="unmatched">Sin match</option>
                                </select>

                                <div class="w-full sm:w-[200px]">
                                    <SelectField v-model="filters.branch" :options="branchFilterOptions" placeholder="Todas las sucursales" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="filteredAssignments.length" class="grid gap-4 p-4 sm:p-5 lg:grid-cols-2">
                        <article
                            v-for="item in filteredAssignments"
                            :key="item.id"
                            class="rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-md"
                        >
                            <!-- Header row -->
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="truncate text-base font-bold tracking-tight">
                                        {{ item.employee_name }}
                                    </h3>
                                    <p
                                        v-if="item.aliases && item.aliases.length > 0"
                                        class="mt-0.5 text-xs text-amber-600 dark:text-amber-400"
                                        :title="`Variantes fusionadas: ${item.aliases.map((a) => a.employee_name).join(', ')}`"
                                    >
                                        También: {{ item.aliases.map((a) => a.employee_name).join(' · ') }}
                                    </p>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        {{ item.normalized_name || 'Sin nombre normalizado' }}
                                    </p>
                                </div>

                                <div class="flex shrink-0 flex-col items-end gap-1.5">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                        :class="statusClass(item.ui_status)"
                                    >
                                        {{ statusLabel(item.ui_status) }}
                                    </span>
                                    <span
                                        v-if="item.was_manual_reviewed"
                                        class="inline-flex rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
                                    >
                                        Ajuste manual
                                    </span>
                                </div>
                            </div>

                            <!-- Details -->
                            <div class="mt-4 space-y-2.5">
                                <div class="flex items-center gap-2 text-sm">
                                    <Building2 class="size-4 shrink-0 text-muted-foreground" />
                                    <span class="font-medium">{{ item.branch_name || 'Sin sucursal asignada' }}</span>
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <ArrowRightLeft class="size-4 shrink-0 text-muted-foreground" />
                                    <span>{{ item.match_label || 'Pendiente' }}</span>
                                    <span class="text-muted-foreground">·</span>
                                    <span class="text-muted-foreground">{{ formatConfidence(item.confidence) }}</span>
                                </div>

                                <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                    <ClipboardList class="size-4 shrink-0" />
                                    <span>{{ item.match_explanation || 'Sin explicación' }}</span>
                                </div>

                                <div
                                    v-if="item.notes"
                                    class="rounded-2xl border border-border/70 bg-muted/30 px-3 py-3 text-sm text-muted-foreground"
                                >
                                    {{ item.notes }}
                                </div>
                            </div>

                            <!-- Footer -->
                            <div class="mt-4 flex items-center justify-between border-t pt-4">
                                <p class="text-xs text-muted-foreground">{{ item.updated_at ?? '—' }}</p>
                                <button
                                    type="button"
                                    class="inline-flex h-9 items-center gap-1.5 rounded-2xl bg-sky-500 px-4 text-xs font-bold text-white transition hover:bg-sky-400"
                                    @click="openAssignModal(item)"
                                >
                                    <PenSquare class="size-3.5" />
                                    Asignar sucursal
                                </button>
                            </div>
                        </article>
                    </div>

                    <!-- Empty state -->
                    <div v-else class="px-4 py-12 text-center sm:px-5">
                        <UserRound class="mx-auto size-6 text-muted-foreground" />
                        <p class="mt-3 text-sm font-semibold">
                            {{ hasNoAssignments ? 'Sin asignaciones' : 'No hay registros visibles' }}
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            {{
                                hasNoAssignments
                                    ? 'No se han generado asignaciones para este periodo todavía.'
                                    : 'No se encontraron coincidencias con los filtros actuales.'
                            }}
                        </p>
                        <button
                            v-if="hasNoAssignments && selected_period_id"
                            type="button"
                            class="mt-5 inline-flex h-10 items-center gap-2 rounded-2xl bg-slate-900 px-5 text-sm font-bold text-white transition hover:bg-slate-700 disabled:opacity-50"
                            :disabled="autoMatchForm.processing"
                            @click="runAutoMatch"
                        >
                            <Wand2 class="size-4" />
                            {{ autoMatchForm.processing ? 'Procesando...' : 'Inicializar cruce automático' }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- Incidences / Hires / Leavers -->
            <section
                v-if="incidences.length || hires.length || leavers.length"
                class="grid gap-6 xl:grid-cols-3"
            >
                <div class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex items-center gap-2">
                            <AlertTriangle class="size-5 text-amber-500" />
                            <h2 class="text-lg font-bold tracking-tight">Incidencias</h2>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">Registros que requieren atención manual.</p>
                    </div>

                    <div v-if="incidences.length" class="space-y-3 p-4 sm:p-5">
                        <article
                            v-for="item in incidences"
                            :key="`inc-${item.id}`"
                            class="rounded-2xl border border-border/70 bg-background px-4 py-4"
                        >
                            <p class="font-semibold">{{ item.employee_name }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ item.branch_name || 'Sin sucursal asignada' }}</p>
                            <p class="mt-2 text-xs text-muted-foreground">{{ item.notes || item.match_explanation }}</p>
                            <button
                                type="button"
                                class="mt-3 inline-flex h-8 items-center gap-1.5 rounded-xl bg-sky-500 px-3 text-xs font-bold text-white transition hover:bg-sky-400"
                                @click="openAssignModal(item)"
                            >
                                <PenSquare class="size-3" />
                                Asignar
                            </button>
                        </article>
                    </div>

                    <div v-else class="px-4 py-10 text-center text-sm text-muted-foreground">Sin incidencias.</div>
                </div>

                <div class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex items-center gap-2">
                            <UserPlus class="size-5 text-emerald-500" />
                            <h2 class="text-lg font-bold tracking-tight">Altas del periodo</h2>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">Empleados que aparecen en este periodo y no en el anterior.</p>
                    </div>

                    <div v-if="hires.length" class="space-y-3 p-4 sm:p-5">
                        <article
                            v-for="item in hires"
                            :key="`hire-${item.id}`"
                            class="rounded-2xl border border-border/70 bg-background px-4 py-4"
                        >
                            <p class="font-semibold">{{ item.employee_name }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ item.branch_name || 'Sin sucursal asignada' }}</p>
                        </article>
                    </div>

                    <div v-else-if="!summary.roster_calculado" class="px-4 py-10 text-center text-sm text-muted-foreground">
                        El roster de colaboradores de este periodo aún no se ha calculado — corre "Actualizar BD" en Histórico General primero.
                    </div>
                    <div v-else class="px-4 py-10 text-center text-sm text-muted-foreground">Sin altas detectadas.</div>
                </div>

                <div class="app-card overflow-hidden">
                    <div class="border-b px-4 py-4 sm:px-5">
                        <div class="flex items-center gap-2">
                            <UserMinus class="size-5 text-rose-500" />
                            <h2 class="text-lg font-bold tracking-tight">Bajas del periodo</h2>
                        </div>
                        <p class="mt-1 text-sm text-muted-foreground">Empleados vistos en el periodo anterior pero no en el actual.</p>
                    </div>

                    <div v-if="leavers.length" class="space-y-3 p-4 sm:p-5">
                        <article
                            v-for="item in leavers"
                            :key="`leave-${item.id}`"
                            class="rounded-2xl border border-border/70 bg-background px-4 py-4"
                        >
                            <p class="font-semibold">{{ item.employee_name }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">Última sucursal conocida: {{ item.branch_name || 'Sin sucursal asignada' }}</p>
                            <p class="mt-2 text-xs text-muted-foreground">Último periodo: {{ item.period_label || '—' }}</p>
                        </article>
                    </div>

                    <div v-else-if="!summary.roster_calculado" class="px-4 py-10 text-center text-sm text-muted-foreground">
                        El roster de colaboradores de este periodo aún no se ha calculado — corre "Actualizar BD" en Histórico General primero.
                    </div>
                    <div v-else class="px-4 py-10 text-center text-sm text-muted-foreground">Sin bajas detectadas.</div>
                </div>
            </section>
        </div>
    </div>

    <!-- Assignment modal -->
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="modal.open"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                @click.self="closeModal"
            >
                <Transition
                    enter-active-class="transition duration-200"
                    enter-from-class="scale-95 opacity-0"
                    enter-to-class="scale-100 opacity-100"
                    leave-active-class="transition duration-150"
                    leave-from-class="scale-100 opacity-100"
                    leave-to-class="scale-95 opacity-0"
                >
                    <div
                        v-if="modal.open"
                        class="w-full max-w-md overflow-hidden rounded-[2rem] bg-white shadow-2xl dark:bg-slate-900"
                    >
                        <!-- Modal header -->
                        <div class="flex items-start justify-between border-b px-6 py-5">
                            <div>
                                <p class="text-xs font-black uppercase tracking-widest text-sky-500">Asignar sucursal</p>
                                <h2 class="mt-1 truncate text-xl font-black">{{ modal.employeeName }}</h2>
                            </div>
                            <button
                                type="button"
                                class="mt-0.5 rounded-xl p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                                @click="closeModal"
                            >
                                <X class="size-5" />
                            </button>
                        </div>

                        <!-- Modal body -->
                        <div class="space-y-4 p-6">
                            <!-- Period info (read-only) -->
                            <div
                                v-if="selected_period_label"
                                class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800"
                            >
                                <p class="text-xs text-slate-500">Periodo</p>
                                <p class="mt-0.5 text-sm font-bold">{{ selected_period_label }}</p>
                            </div>

                            <!-- Branch search -->
                            <div class="space-y-2">
                                <label class="text-sm font-semibold">Sucursal</label>
                                <div class="relative">
                                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        v-model="modal.branchQuery"
                                        type="text"
                                        class="app-input h-10 pl-10"
                                        placeholder="Filtrar sucursales..."
                                    />
                                </div>
                                <select v-model="modal.branch_id" class="app-input h-11">
                                    <option value="">— Selecciona sucursal —</option>
                                    <option
                                        v-for="branch in modalBranches"
                                        :key="branch.id"
                                        :value="String(branch.id)"
                                    >
                                        {{ branch.name }}
                                    </option>
                                </select>
                                <p v-if="!modalBranches.length" class="text-xs text-rose-500">
                                    Sin resultados para "{{ modal.branchQuery }}".
                                </p>
                            </div>

                            <!-- Notes -->
                            <div class="space-y-2">
                                <label class="text-sm font-semibold">Notas <span class="font-normal text-muted-foreground">(opcional)</span></label>
                                <textarea
                                    v-model="modal.notes"
                                    class="app-input min-h-[80px] resize-none"
                                    placeholder="Razón del ajuste manual, aclaración, etc."
                                />
                            </div>
                        </div>

                        <!-- Modal footer -->
                        <div class="flex items-center justify-end gap-3 border-t px-6 py-4">
                            <button
                                type="button"
                                class="h-10 rounded-2xl px-4 text-sm font-semibold text-slate-600 transition hover:bg-slate-100"
                                @click="closeModal"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-10 items-center gap-2 rounded-2xl bg-sky-500 px-5 text-sm font-black text-white transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="modal.saving"
                                @click="saveModal"
                            >
                                <PenSquare class="size-4" />
                                {{ modal.saving ? 'Guardando...' : 'Guardar asignación' }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
