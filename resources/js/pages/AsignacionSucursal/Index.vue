<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import {
    AlertTriangle,
    CheckCircle2,
    Search,
    Sparkles,
    UserMinus,
    UserPlus,
    UserRound,
    Wand2,
} from 'lucide-vue-next'

import SelectField from '@/components/forms/SelectField.vue'
import AssignBranchModal from '@/components/asignaciones/AssignBranchModal.vue'
import BranchHeadcountPanel from '@/components/asignaciones/BranchHeadcountPanel.vue'
import EmployeeAssignmentCard from '@/components/asignaciones/EmployeeAssignmentCard.vue'
import PeriodMovementsPanel from '@/components/asignaciones/PeriodMovementsPanel.vue'
import type { Assignment, BranchHeadcount, Branch, PeriodOption, RosterMovementItem } from '@/types/asignaciones'

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

// Breadcrumb en el navbar de arriba en vez de repetir el título en un div gigante
// dentro de la página — mismo patrón que Dashboard.vue/Periodos/Index.vue.
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Colaboradores', href: '/asignaciones-empleado-sucursal' }],
    },
})

const filters = reactive({
    query: '',
    status: 'all',
    branch: '',
})

const branchFilterOptions = computed(() => {
    const names = new Set(props.assignments.map((a) => a.branch_name).filter((n): n is string => !!n))
    return [
        { value: '', label: 'Todas las sucursales' },
        ...Array.from(names).sort().map((n) => ({ value: n, label: n })),
    ]
})

// Distribución EXACTA de colaboradores por sucursal — parte del catálogo completo
// de sucursales reales (nunca solo las que aparecen en assignments), así una
// sucursal con 0 colaboradores este periodo también se ve, no desaparece.
const branchDistribution = computed<BranchHeadcount[]>(() => {
    const counts = new Map<string, number>()
    for (const b of props.branches) counts.set(b.name, 0)
    for (const a of props.assignments) {
        if (!a.branch_name) continue
        counts.set(a.branch_name, (counts.get(a.branch_name) ?? 0) + 1)
    }
    return Array.from(counts.entries())
        .map(([name, count]) => ({ name, count }))
        .sort((a, b) => b.count - a.count)
})

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

const periodSelectOptions = computed(() => props.periods.map((p) => ({ value: p.id, label: p.label })))

const statusFilterOptions = [
    { value: 'all', label: 'Todos' },
    { value: 'matched', label: 'Match correcto' },
    { value: 'manual', label: 'Manual' },
    { value: 'pending', label: 'Pendiente' },
    { value: 'unmatched', label: 'Sin match' },
]

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
    branch_id: '',
    notes: '',
})

function openAssignModal(item: Assignment) {
    modal.open = true
    modal.saving = false
    modal.employeeId = item.employee_id ?? null
    modal.assignmentId = item.id
    modal.employeeName = item.employee_name
    modal.branch_id = item.branch_id ? String(item.branch_id) : ''
    modal.notes = item.notes ?? ''
}

function closeModal() {
    modal.open = false
    modal.saving = false
}

function saveModal(payload: { branchId: string; notes: string }) {
    modal.saving = true

    if (modal.employeeId && props.selected_period_id) {
        router.post(
            `/empleados/${modal.employeeId}/asignar-sucursal`,
            {
                branch_id: payload.branchId,
                period_id: props.selected_period_id,
                notes: payload.notes || null,
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
                branch_id: payload.branchId,
                notes: payload.notes || null,
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

// Paginado — con "Todos" seleccionado y sin buscar, la lista completa puede ser
// enorme (cientos de tarjetas de golpe). Nunca se oculta nada, solo se reparte en
// páginas; cualquier cambio de filtro o de periodo regresa a la página 1.
const PAGE_SIZE = 12
const page = ref(1)

watch([() => filters.query, () => filters.status, () => filters.branch, () => props.assignments], () => {
    page.value = 1
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredAssignments.value.length / PAGE_SIZE)))
const paginatedAssignments = computed(() => {
    const start = (page.value - 1) * PAGE_SIZE
    return filteredAssignments.value.slice(start, start + PAGE_SIZE)
})
const pageRangeLabel = computed(() => {
    if (!filteredAssignments.value.length) return ''
    const start = (page.value - 1) * PAGE_SIZE + 1
    const end = Math.min(page.value * PAGE_SIZE, filteredAssignments.value.length)
    return `${start}–${end} de ${filteredAssignments.value.length}`
})

const hasNoAssignments = computed(() => props.assignments.length === 0)
</script>

<template>
    <Head title="Colaboradores" />

    <div class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50/40 p-4 sm:p-6 dark:bg-none dark:bg-background">
        <div class="mx-auto max-w-screen-2xl space-y-5">

            <!-- El título/ubicación ya vive arriba en el navbar (breadcrumb). Los KPIs ya
                 no son tarjetononas en su propia fila — son chips chicos, del mismo alto
                 que el select, a la derecha de "Periodo". -->
            <section class="flex flex-wrap items-center gap-3">
                <label class="shrink-0 text-sm font-semibold text-slate-600 dark:text-slate-300">Periodo:</label>
                <div class="w-full sm:w-64">
                    <SelectField v-model="selectedPeriodId" :options="periodSelectOptions" placeholder="Selecciona un periodo" />
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div
                        v-for="stat in [
                            { icon: UserRound, label: 'Activos', value: summary.total, fg: 'text-slate-500 dark:text-slate-400' },
                            { icon: CheckCircle2, label: 'Con sucursal', value: summary.with_branch, fg: 'text-emerald-500 dark:text-emerald-400' },
                            { icon: AlertTriangle, label: 'Incidencias', value: summary.needs_review, fg: 'text-amber-500 dark:text-amber-400' },
                            { icon: Sparkles, label: 'Manuales', value: summary.manual, fg: 'text-sky-500 dark:text-sky-400' },
                            { icon: UserPlus, label: 'Altas', value: summary.hires, fg: 'text-emerald-500 dark:text-emerald-400' },
                            { icon: UserMinus, label: 'Bajas', value: summary.leavers, fg: 'text-rose-500 dark:text-rose-400' },
                        ]"
                        :key="stat.label"
                        class="flex h-11 items-center gap-1.5 rounded-2xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-600 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-slate-800 dark:bg-card dark:text-slate-300 dark:hover:shadow-black/20"
                    >
                        <component :is="stat.icon" :class="['size-3.5 shrink-0', stat.fg]" />
                        <span class="font-black text-slate-950 dark:text-slate-50">{{ stat.value }}</span>
                        <span class="text-slate-400 dark:text-slate-500">{{ stat.label }}</span>
                    </div>
                </div>
            </section>

            <!-- Colaboradores por sucursal — exacto, antes de la lista -->
            <BranchHeadcountPanel :distribution="branchDistribution" :hires="summary.hires" :leavers="summary.leavers" />

            <!-- Movimientos del periodo — incidencias / altas / bajas -->
            <PeriodMovementsPanel
                :incidences="incidences"
                :hires="hires"
                :leavers="leavers"
                :roster-calculado="summary.roster_calculado"
                @assign="openAssignModal"
            />

            <!-- Employee cards -->
            <section class="app-card overflow-hidden">
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

                            <div class="w-full sm:w-[170px]">
                                <SelectField v-model="filters.status" :options="statusFilterOptions" placeholder="Todos" />
                            </div>

                            <div class="w-full sm:w-[200px]">
                                <SelectField v-model="filters.branch" :options="branchFilterOptions" placeholder="Todas las sucursales" />
                            </div>
                        </div>
                    </div>
                </div>

                <template v-if="filteredAssignments.length">
                    <div class="grid gap-4 p-4 sm:p-5 lg:grid-cols-2">
                        <EmployeeAssignmentCard
                            v-for="item in paginatedAssignments"
                            :key="item.id"
                            :item="item"
                            @assign="openAssignModal(item)"
                        />
                    </div>

                    <!-- Paginado — nunca se oculta nada, solo se reparte; "Todos" sin buscar
                         puede ser una lista enorme de golpe. -->
                    <div v-if="totalPages > 1" class="flex items-center justify-between gap-3 border-t px-4 py-3 sm:px-5">
                        <p class="text-xs text-muted-foreground">{{ pageRangeLabel }}</p>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex h-9 items-center rounded-xl border border-border/70 px-3 text-xs font-semibold transition duration-200 hover:-translate-y-0.5 hover:bg-muted disabled:pointer-events-none disabled:opacity-40"
                                :disabled="page === 1"
                                @click="page--"
                            >
                                Anterior
                            </button>
                            <p class="text-xs font-semibold text-muted-foreground">Página {{ page }} de {{ totalPages }}</p>
                            <button
                                type="button"
                                class="inline-flex h-9 items-center rounded-xl border border-border/70 px-3 text-xs font-semibold transition duration-200 hover:-translate-y-0.5 hover:bg-muted disabled:pointer-events-none disabled:opacity-40"
                                :disabled="page === totalPages"
                                @click="page++"
                            >
                                Siguiente
                            </button>
                        </div>
                    </div>
                </template>

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
            </section>
        </div>
    </div>

    <AssignBranchModal
        :open="modal.open"
        :saving="modal.saving"
        :employee-name="modal.employeeName"
        :period-label="selected_period_label"
        :branches="branches"
        :initial-branch-id="modal.branch_id"
        :initial-notes="modal.notes"
        @close="closeModal"
        @save="saveModal"
    />
</template>
