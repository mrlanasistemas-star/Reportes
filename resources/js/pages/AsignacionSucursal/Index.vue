<script setup lang="ts">
/**
 * Pantalla: COLABORADORES / Asignación de sucursal (/asignaciones-empleado-sucursal).
 *
 * Para qué sirve: para el periodo elegido muestra la plantilla laboral (roster
 * NOI deduplicado), los pendientes por conciliar, los movimientos (altas,
 * reingresos, bajas confirmadas, cambios de sucursal, retornos), el histórico
 * laboral, la distribución por sucursal y las tarjetas de asignación de
 * sucursal por colaborador, donde se asigna/corrige la sucursal y el puesto.
 *
 * Origen de los datos: `App\Http\Controllers\EmployeeBranchAssignmentController@index`
 * renderiza `AsignacionSucursal/Index` con asignaciones, sucursales, periodos,
 * selección actual (periodo/sucursal), resumen de conteos, incidencias,
 * altas/bajas, datos laborales (`labor`), histórico y permiso de conciliar.
 * El filtro de sucursal y el periodo se aplican en el SERVIDOR.
 *
 * Rutas que usa:
 * - GET  /asignaciones-empleado-sucursal?period_id&branch_id   cambiar periodo/sucursal
 * - POST /asignaciones-empleado-sucursal/match-automatico      inicializar cruce automático
 * - POST /asignaciones-empleado-sucursal/{id}/match-manual     asignar sucursal a un registro sin colaborador
 * - POST /empleados/{id}/asignar-sucursal                      asignar sucursal a un colaborador en el periodo
 * - POST /empleados/{id}/puesto                                actualizar el puesto
 * (La conciliación de pendientes la hace PendingReconciliationPanel.)
 */
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, Search, UserRound, Wand2 } from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

import AssignBranchModal from '@/components/asignaciones/AssignBranchModal.vue';
import BranchHeadcountPanel from '@/components/asignaciones/BranchHeadcountPanel.vue';
import EmployeeAssignmentCard from '@/components/asignaciones/EmployeeAssignmentCard.vue';
import LaborHistoryPanel from '@/components/asignaciones/LaborHistoryPanel.vue';
import PendingReconciliationPanel from '@/components/asignaciones/PendingReconciliationPanel.vue';
import PeriodMovementsPanel from '@/components/asignaciones/PeriodMovementsPanel.vue';
import RosterKpiStrip from '@/components/asignaciones/RosterKpiStrip.vue';
import RosterOverviewPanel from '@/components/asignaciones/RosterOverviewPanel.vue';
import SelectField from '@/components/forms/SelectField.vue';
import type {
    Assignment,
    BranchHeadcount,
    Branch,
    LaborData,
    LaborHistoryRow,
    PeriodOption,
    RosterMovementItem,
} from '@/types/asignaciones';

/**
 * Props de EmployeeBranchAssignmentController@index (todas con valor por defecto
 * para que la pantalla funcione sin periodo seleccionado).
 * - assignments: registros de asignación del periodo (con alias, estado UI, confianza).
 * - branches / periods: catálogos (periodos con fechas de inicio y fin).
 * - selected_period_id / selected_period_label / selected_branch_id: selección vigente.
 * - summary: conteos (asignaciones, match, plantilla, bajas confirmadas/pendientes, caída masiva).
 * - incidences: registros sin sucursal o por revisar.
 * - hires / leavers: altas y bajas (respaldo cuando no hay `labor`).
 * - labor: plantilla, activos, pendientes y movimientos calculados del periodo.
 * - labor_history: histórico laboral por periodo.
 * - can_reconcile: el usuario puede conciliar pendientes.
 */
const props = withDefaults(
    defineProps<{
        assignments: Assignment[];
        branches: Branch[];
        periods: PeriodOption[];
        selected_period_id?: number | null;
        selected_period_label?: string | null;
        selected_branch_id?: number | null;
        summary?: {
            total: number;
            matched: number;
            manual: number;
            pending: number;
            unmatched: number;
            with_branch: number;
            without_branch: number;
            high_confidence: number;
            needs_review: number;
            hires: number;
            leavers: number;
            leavers_confirmadas: number;
            leavers_pendientes: number;
            pendientes_conciliacion: number;
            plantilla: number;
            roster_calculado: boolean;
            mass_drop_detected: boolean;
            drop_ratio: number;
        };
        incidences?: Assignment[];
        hires?: RosterMovementItem[];
        leavers?: RosterMovementItem[];
        labor?: LaborData | null;
        labor_history?: LaborHistoryRow[];
        can_reconcile?: boolean;
    }>(),
    {
        assignments: () => [],
        branches: () => [],
        periods: () => [],
        selected_period_id: null,
        selected_period_label: null,
        selected_branch_id: null,
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
            leavers_confirmadas: 0,
            leavers_pendientes: 0,
            pendientes_conciliacion: 0,
            plantilla: 0,
            roster_calculado: false,
            mass_drop_detected: false,
            drop_ratio: 0,
        }),
        incidences: () => [],
        hires: () => [],
        leavers: () => [],
        labor: null,
        labor_history: () => [],
        can_reconcile: false,
    },
);

/**
 * Breadcrumb en el navbar de arriba en vez de repetir el título en un div gigante
 * dentro de la página — mismo patrón que Dashboard.vue/Periodos/Index.vue.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Colaboradores', href: '/asignaciones-empleado-sucursal' },
        ],
    },
});

/** Filtros locales (en el navegador) de las tarjetas: texto y estado del match. */
const filters = reactive({
    query: '',
    status: 'all',
});

/**
 * Opciones del filtro de sucursal. Es un filtro en el SERVIDOR (`branch_id`):
 * conteos, plantilla, movimientos e historial se recalculan para la sucursal
 * elegida, no solo la tabla.
 */
const branchFilterOptions = computed(() => [
    { value: '', label: 'Todas las sucursales' },
    ...props.branches.map((b) => ({ value: String(b.id), label: b.name })),
]);

/** Sucursal elegida (texto). Al asignarla recarga la página con el periodo actual y la nueva sucursal. */
const selectedBranchId = computed({
    get: () =>
        props.selected_branch_id ? String(props.selected_branch_id) : '',
    set: (value: string) => {
        router.get(
            '/asignaciones-empleado-sucursal',
            {
                period_id: props.selected_period_id || undefined,
                branch_id: value || undefined,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    },
});

/**
 * Base de la distribución por sucursal: 'plantilla' si el roster NOI del
 * periodo ya está calculado; si no, 'asignaciones' (y el panel lo indica).
 */
const distributionBasis = computed<'plantilla' | 'asignaciones'>(() =>
    props.labor?.roster.calculado ? 'plantilla' : 'asignaciones',
);

/**
 * Distribución EXACTA por sucursal — cuenta PERSONAS de la plantilla NOI (una
 * persona con varios registros de asignación cuenta una vez). Solo si el roster
 * del periodo aún no existe se cae a registros de asignación. Parte del
 * catálogo completo de sucursales reales: una sucursal con 0 también se ve.
 * Orden: mayor conteo primero y, en empate, alfabético.
 */
const branchDistribution = computed<BranchHeadcount[]>(() => {
    const counts = new Map<string, number>();

    for (const b of props.branches) {
        counts.set(b.name, 0);
    }

    const names =
        distributionBasis.value === 'plantilla'
            ? (props.labor?.active ?? []).map((a) => a.branch_name)
            : props.assignments.map((a) => a.branch_name);

    for (const name of names) {
        if (name) {
            counts.set(name, (counts.get(name) ?? 0) + 1);
        }
    }

    return Array.from(counts.entries())
        .map(([name, count]) => ({ name, count }))
        .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));
});

/** Periodo seleccionado completo (con fechas). */
const selectedPeriod = computed(
    () => props.periods.find((p) => p.id === props.selected_period_id) ?? null,
);
/** Rango "dd/mm/aaaa – dd/mm/aaaa" del periodo seleccionado (null si le faltan fechas). */
const periodRange = computed(() => {
    const p = selectedPeriod.value;

    if (!p?.start_date || !p?.end_date) {
        return null;
    }

    const fmt = (d: string) => d.split('-').reverse().join('/');

    return `${fmt(p.start_date)} – ${fmt(p.end_date)}`;
});
/** Periodo elegido (texto). Al asignarlo recarga la página con el nuevo periodo y la sucursal actual. */
const selectedPeriodId = computed({
    get: () =>
        props.selected_period_id ? String(props.selected_period_id) : '',
    set: (value: string) => {
        router.get(
            '/asignaciones-empleado-sucursal',
            {
                period_id: value || undefined,
                branch_id: props.selected_branch_id || undefined,
            },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    },
});

/** Opciones del selector de periodo. */
const periodSelectOptions = computed(() =>
    props.periods.map((p) => ({ value: p.id, label: p.label })),
);

/** Opciones del filtro de estado de match (valores de `ui_status`). */
const statusFilterOptions = [
    { value: 'all', label: 'Todos' },
    { value: 'matched', label: 'Match correcto' },
    { value: 'manual', label: 'Manual' },
    { value: 'pending', label: 'Pendiente' },
    { value: 'unmatched', label: 'Sin match' },
];

/** Formulario del cruce automático — solo se usa desde el botón "Inicializar" del estado vacío. */
const autoMatchForm = useForm({
    period_id: props.selected_period_id ?? null,
});

/** Lanza el cruce automático de colaboradores con sucursal para el periodo seleccionado. */
function runAutoMatch() {
    autoMatchForm.period_id = props.selected_period_id ?? null;
    autoMatchForm.post('/asignaciones-empleado-sucursal/match-automatico', {
        preserveScroll: true,
    });
}

/** Estado del modal de asignación de sucursal por colaborador/registro. */
const modal = reactive({
    open: false,
    saving: false,
    employeeId: null as number | null,
    assignmentId: null as number | null,
    employeeName: '',
    branch_id: '',
    notes: '',
});

/** Abre el modal precargado con el colaborador/registro, su sucursal actual y sus notas. */
function openAssignModal(item: Assignment) {
    modal.open = true;
    modal.saving = false;
    modal.employeeId = item.employee_id ?? null;
    modal.assignmentId = item.id;
    modal.employeeName = item.employee_name;
    modal.branch_id = item.branch_id ? String(item.branch_id) : '';
    modal.notes = item.notes ?? '';
}

/** Cierra el modal de asignación. */
function closeModal() {
    modal.open = false;
    modal.saving = false;
}

/** Guarda el puesto del colaborador (vacío = null). Ignora registros sin colaborador ligado. */
function updatePosition(
    employeeId: number | null | undefined,
    position: string,
) {
    if (!employeeId) {
        return;
    }

    router.post(
        `/empleados/${employeeId}/puesto`,
        { position: position || null },
        { preserveScroll: true },
    );
}

/**
 * Guarda la asignación del modal. Con colaborador y periodo usa
 * /empleados/{id}/asignar-sucursal; si no, corrige el registro de asignación
 * con /match-manual. Cierra el modal solo si el servidor lo aceptó.
 */
function saveModal(payload: { branchId: string; notes: string }) {
    modal.saving = true;

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
                    modal.saving = false;
                },
            },
        );
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
                    modal.saving = false;
                },
            },
        );
    }
}

/** Asignaciones que cumplen la búsqueda (nombre, nombre normalizado, alias, sucursal, notas) y el estado. */
const filteredAssignments = computed(() => {
    const query = filters.query.trim().toLowerCase();

    return props.assignments.filter((item) => {
        const aliasNames = (item.aliases ?? [])
            .map((a) => a.employee_name.toLowerCase())
            .join(' ');
        const matchesQuery =
            !query ||
            item.employee_name.toLowerCase().includes(query) ||
            (item.normalized_name ?? '').toLowerCase().includes(query) ||
            (item.branch_name ?? '').toLowerCase().includes(query) ||
            (item.notes ?? '').toLowerCase().includes(query) ||
            aliasNames.includes(query);

        const matchesStatus =
            filters.status === 'all' || item.ui_status === filters.status;

        return matchesQuery && matchesStatus;
    });
});

/**
 * Paginado — con "Todos" seleccionado y sin buscar, la lista completa puede ser
 * enorme (cientos de tarjetas de golpe). Nunca se oculta nada, solo se reparte en
 * páginas de PAGE_SIZE tarjetas.
 */
const PAGE_SIZE = 12;
/** Página actual del listado de tarjetas. */
const page = ref(1);

/** Cualquier cambio de filtro o de datos (periodo/sucursal) regresa a la página 1. */
watch(
    [() => filters.query, () => filters.status, () => props.assignments],
    () => {
        page.value = 1;
    },
);

/** Total de páginas (mínimo 1). */
const totalPages = computed(() =>
    Math.max(1, Math.ceil(filteredAssignments.value.length / PAGE_SIZE)),
);
/** Tarjetas de la página actual. */
const paginatedAssignments = computed(() => {
    const start = (page.value - 1) * PAGE_SIZE;

    return filteredAssignments.value.slice(start, start + PAGE_SIZE);
});
/** Texto "inicio–fin de total" del paginado. */
const pageRangeLabel = computed(() => {
    if (!filteredAssignments.value.length) {
        return '';
    }

    const start = (page.value - 1) * PAGE_SIZE + 1;
    const end = Math.min(
        page.value * PAGE_SIZE,
        filteredAssignments.value.length,
    );

    return `${start}–${end} de ${filteredAssignments.value.length}`;
});

/** El periodo no tiene ningún registro de asignación (habilita el cruce automático). */
const hasNoAssignments = computed(() => props.assignments.length === 0);
</script>

<template>
    <Head title="Colaboradores" />

    <div class="min-h-screen bg-background p-4 sm:p-6">
        <div class="mx-auto max-w-screen-2xl space-y-5">
            <!-- Periodo visible siempre: todas las cifras de la página son de este periodo. -->
            <section
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <label
                        for="periodo-colaboradores"
                        class="shrink-0 text-sm font-semibold text-muted-foreground"
                        >Periodo</label
                    >
                    <div class="w-full sm:w-64">
                        <SelectField
                            id="periodo-colaboradores"
                            v-model="selectedPeriodId"
                            :options="periodSelectOptions"
                            placeholder="Selecciona un periodo"
                        />
                    </div>
                </div>
                <p
                    v-if="selected_period_label"
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <CalendarDays class="size-4" aria-hidden="true" />
                    <span class="font-semibold text-foreground">{{
                        selected_period_label
                    }}</span>
                    <span v-if="periodRange" class="tabular-nums"
                        >· {{ periodRange }}</span
                    >
                </p>
            </section>

            <!-- Franja de KPI laborales del periodo -->
            <RosterKpiStrip v-if="labor" :labor="labor" />

            <!-- 1. Plantilla del periodo + activos confirmados (roster NOI deduplicado) -->
            <RosterOverviewPanel
                v-if="labor"
                :roster="labor.roster"
                :active="labor.active"
                :period-label="selected_period_label"
            />

            <!-- 2. Por conciliar (nunca se muestran como bajas) -->
            <PendingReconciliationPanel
                v-if="labor"
                :items="labor.pending"
                :period-id="selected_period_id ?? null"
                :can-reconcile="can_reconcile"
            />

            <!-- 3. Movimientos laborales confirmados + registros sin sucursal -->
            <PeriodMovementsPanel
                :incidences="incidences"
                :hires="labor?.movements.altas ?? hires"
                :reentries="labor?.movements.reingresos ?? []"
                :leavers="labor?.movements.bajas ?? leavers"
                :branch-changes="labor?.movements.cambios_sucursal ?? []"
                :returns="labor?.movements.retornos ?? []"
                :roster-calculado="summary.roster_calculado"
                @assign="openAssignModal"
            />

            <!-- 4. Histórico laboral por periodo -->
            <LaborHistoryPanel
                v-if="labor_history.length"
                :rows="labor_history"
                :selected-period-id="selected_period_id ?? null"
            />

            <!-- 5. Asignación por sucursal -->
            <BranchHeadcountPanel
                :distribution="branchDistribution"
                :basis="distributionBasis"
                :without-branch="labor?.roster.sin_sucursal ?? 0"
                :pending-leavers="summary.leavers_pendientes"
                :mass-drop-detected="summary.mass_drop_detected"
                :drop-ratio="summary.drop_ratio"
            />

            <!-- Tarjetas de asignación por colaborador: filtros, listado paginado y estado vacío -->
            <section class="app-section overflow-hidden">
                <div class="border-b border-border px-4 py-4 sm:px-5">
                    <div
                        class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between"
                    >
                        <div>
                            <h2 class="app-section-title">
                                Asignación de sucursal por colaborador
                            </h2>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Registros de asignación del periodo ({{
                                    summary.total
                                }}; {{ summary.with_branch }} con sucursal,
                                {{ summary.manual }} manuales,
                                {{ summary.needs_review }} por revisar). Son
                                registros, no la plantilla: haz clic en
                                <span class="font-semibold"
                                    >Asignar sucursal</span
                                >
                                para crear o corregir uno.
                            </p>
                        </div>

                        <div class="flex flex-col flex-wrap gap-3 sm:flex-row">
                            <div class="relative w-full sm:w-[260px]">
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <input
                                    v-model="filters.query"
                                    type="search"
                                    autocomplete="off"
                                    class="app-input h-11 pl-10"
                                    placeholder="Buscar empleado, sucursal o nota..."
                                    aria-label="Buscar empleado, sucursal o nota"
                                />
                            </div>

                            <div class="w-full sm:w-[170px]">
                                <SelectField
                                    v-model="filters.status"
                                    aria-label="Filtrar por estado"
                                    :options="statusFilterOptions"
                                    placeholder="Todos"
                                />
                            </div>

                            <div class="w-full sm:w-[200px]">
                                <SelectField
                                    v-model="selectedBranchId"
                                    aria-label="Filtrar por sucursal"
                                    :options="branchFilterOptions"
                                    placeholder="Todas las sucursales"
                                />
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
                            @update-position="
                                (value) =>
                                    updatePosition(item.employee_id, value)
                            "
                        />
                    </div>

                    <!-- Paginado — nunca se oculta nada, solo se reparte; "Todos" sin buscar
                         puede ser una lista enorme de golpe. -->
                    <div
                        v-if="totalPages > 1"
                        class="flex items-center justify-between gap-3 border-t px-4 py-3 sm:px-5"
                    >
                        <p class="text-xs text-muted-foreground">
                            {{ pageRangeLabel }}
                        </p>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="inline-flex h-9 items-center rounded-xl border border-border/70 px-3 text-xs font-semibold transition duration-200 hover:-translate-y-0.5 hover:bg-muted disabled:pointer-events-none disabled:opacity-40"
                                :disabled="page === 1"
                                @click="page--"
                            >
                                Anterior
                            </button>
                            <p
                                class="text-xs font-semibold text-muted-foreground"
                            >
                                Página {{ page }} de {{ totalPages }}
                            </p>
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

                <!-- Estado vacío (con botón de cruce automático si no hay asignaciones) -->
                <div v-else class="px-4 py-12 text-center sm:px-5">
                    <UserRound class="mx-auto size-6 text-muted-foreground" />
                    <p class="mt-3 text-sm font-semibold">
                        {{
                            hasNoAssignments
                                ? 'Sin asignaciones'
                                : 'No hay registros visibles'
                        }}
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
                        {{
                            autoMatchForm.processing
                                ? 'Procesando...'
                                : 'Inicializar cruce automático'
                        }}
                    </button>
                </div>
            </section>
        </div>
    </div>

    <!-- Modal para asignar o corregir la sucursal -->
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
