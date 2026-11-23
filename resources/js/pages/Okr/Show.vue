<script setup lang="ts">
/**
 * Pantalla: SEGUIMIENTO OKR (/okr/{objective}) — detalle de un objetivo.
 *
 * Rediseño 15-oct-2026: la página solo orquesta secciones organizadas según
 * el trabajo real del usuario:
 *   encabezado + acciones → filtros (qué alcance se ve) → resumen del objetivo
 *   → resultados por KPI → SEGUIMIENTO POR GESTOR (protagonista en sucursal)
 *   → colocación semanal → documentos y compromisos → evidencias/histórico → bitácora.
 * Ninguna acción usa pantalla de carga global: cada botón tiene su spinner.
 *
 * Origen de los datos: `App\Http\Controllers\Okr\ObjectiveController@show`
 * renderiza `Okr/Show` con el objetivo, sus KR, check-ins, evidencias,
 * alertas, warnings, KPIs, bitácora, banderas de permiso y catálogos.
 *
 * Rutas que usa directamente: POST /okr/{id}/refresh (recalcular),
 * DELETE /okr/{id} (cancelar OKR) y DELETE /okr/{id}/key-results/{kr}
 * (quitar KR en borrador). El resto (activar, carta, warnings, colocación,
 * metas, pesos, check-in, evidencias) lo llaman los diálogos hijos.
 */
import { router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Bell,
    CalendarRange,
    CircleHelp,
    LineChart,
    Target,
    Upload,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import {
    appSwal as Swal,
    dangerDialog,
} from '@/components/feedback/ConfirmDialog';
import OkrActivationDialog from '@/components/okr/OkrActivationDialog.vue';
import OkrCheckInDialog from '@/components/okr/OkrCheckInDialog.vue';
import OkrCommitmentLetterDialog from '@/components/okr/OkrCommitmentLetterDialog.vue';
import OkrCompleteKrDialog from '@/components/okr/OkrCompleteKrDialog.vue';
import OkrEditGoalDialog from '@/components/okr/OkrEditGoalDialog.vue';
import OkrEvidenceUploadDialog from '@/components/okr/OkrEvidenceUploadDialog.vue';
import OkrPlacementUploadDialog from '@/components/okr/OkrPlacementUploadDialog.vue';
import OkrPlacementWeeksTable from '@/components/okr/OkrPlacementWeeksTable.vue';
import OkrWarningDialog from '@/components/okr/OkrWarningDialog.vue';
import OkrWeeklyHistory from '@/components/okr/OkrWeeklyHistory.vue';
import OkrWeightsDialog from '@/components/okr/OkrWeightsDialog.vue';
import OkrAuditTrail from '@/components/okr/show/OkrAuditTrail.vue';
import OkrDocumentsPanel from '@/components/okr/show/OkrDocumentsPanel.vue';
import OkrEvaluationContext from '@/components/okr/show/OkrEvaluationContext.vue';
import OkrEvidencePanel from '@/components/okr/show/OkrEvidencePanel.vue';
import OkrGestorTable from '@/components/okr/show/OkrGestorTable.vue';
import OkrKpiResultsTable from '@/components/okr/show/OkrKpiResultsTable.vue';
import OkrObjectiveSummary from '@/components/okr/show/OkrObjectiveSummary.vue';
import OkrScopeFilters from '@/components/okr/show/OkrScopeFilters.vue';
import OkrTrackingHeader from '@/components/okr/show/OkrTrackingHeader.vue';
import { Button } from '@/components/ui/button';
import { useTour } from '@/composables/useTour';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

/**
 * Props de ObjectiveController@show.
 * - objective: el OKR con alcance, semanas de colocación, seguimiento por gestor, padre/hijos, etc.
 * - keyResults / checkIns / correctiveActions / evidences / alerts / warnings / auditLogs: datos de cada sección.
 * - kpis: catálogo para configurar KR.
 * - can*: permisos efectivos del usuario sobre este OKR (deciden qué botones se ven).
 * - filterBranches / filterPeriods: opciones de OkrScopeFilters.
 * - users: participantes posibles del check-in.
 */
const props = defineProps<{
    objective: any;
    keyResults: any[];
    checkIns: any[];
    correctiveActions: any[];
    evidences: any[];
    alerts: any[];
    warnings: any[];
    kpis: any[];
    auditLogs: any[];
    canManage: boolean;
    canAssign: boolean;
    canUpdate: boolean;
    canCheckin: boolean;
    canUploadEvidence: boolean;
    filterBranches: { id: number; name: string }[];
    filterPeriods: { id: number; label: string }[];
    users: { id: number; name: string }[];
}>();

/** Objetivo (propio o de un gestor hijo) para el que se abre el diálogo de completar KR. */
type CompleteTarget = {
    id: number;
    title: string;
    employee: string | null;
    scope_type: string;
    branch_id: number | null;
    employee_id: number | null;
    start_date: string;
    existing?: any[];
};

/** El OKR es de sucursal (no individual de colaborador). */
const isBranch = computed(() => props.objective.scope_type === 'branch');
/** El OKR tiene semanas de colocación configuradas. */
const hasPlacement = computed(
    () => (props.objective.placement_weeks ?? []).length > 0,
);
/** Se puede cargar colocación: hay semanas, no es de solo lectura, hay permiso de evidencia y es de sucursal. */
const canUploadPlacement = computed(
    () =>
        hasPlacement.value &&
        !props.objective.is_read_only &&
        props.canUploadEvidence &&
        isBranch.value,
);

// Recorrido guiado del módulo ("¿Cómo funciona este OKR?").
const { startTour } = useTour();

// Visibilidad de cada diálogo y su contexto (KR en edición, objetivo a completar, semana a cargar).
const activationOpen = ref(false);
const letterOpen = ref(false);
const warningOpen = ref(false);
const checkInOpen = ref(false);
const evidenceOpen = ref(false);
const weightsOpen = ref(false);
const editGoalOpen = ref(false);
const editingKr = ref<any | null>(null);
const completeOpen = ref(false);
const completeTarget = ref<CompleteTarget | null>(null);
const uploadOpen = ref(false);
const uploadWeek = ref<any | null>(null);
/** Indica que está en curso el recálculo con Reportería (muestra un aviso flotante). */
const refreshing = ref(false);

/** Ya existe un check-in para la semana actual del OKR (el diálogo lo advierte). */
const alreadyCheckedInThisWeek = computed(() =>
    props.checkIns.some((c) => c.week_number === props.objective.current_week),
);

/** Abre el diálogo de completar KR para el objetivo indicado. */
function openComplete(target: CompleteTarget) {
    completeTarget.value = target;
    completeOpen.value = true;
}

/** Configura los KR de ESTE objetivo (pasa los KR existentes para editarlos). */
function configureSelf() {
    openComplete({
        id: props.objective.id,
        title: props.objective.title,
        employee: props.objective.employee?.full_name ?? null,
        scope_type: props.objective.scope_type,
        branch_id: props.objective.branch?.id ?? null,
        employee_id: props.objective.employee?.id ?? null,
        start_date: props.objective.start_date,
        existing: props.keyResults,
    });
}

/**
 * Configura los KR del OKR individual de un gestor (fila de "Seguimiento por
 * gestor"); hereda la sucursal y fecha de inicio del objetivo padre.
 */
function configureChild(row: any) {
    openComplete({
        id: row.id,
        title: row.title,
        employee: row.employee,
        scope_type: 'employee',
        branch_id: props.objective.branch?.id ?? null,
        employee_id: row.employee_id ?? null,
        start_date: props.objective.start_date,
        existing: row.key_results ?? [],
    });
}

/**
 * El diálogo de activación detectó un objetivo sin KR: cierra la activación y
 * abre la configuración del objetivo propio o del gestor hijo correspondiente.
 */
function configureFromActivation(objectiveId: number) {
    activationOpen.value = false;

    if (objectiveId === props.objective.id) {
        configureSelf();

        return;
    }

    const row = (props.objective.gestor_tracking ?? []).find(
        (g: any) => g.id === objectiveId,
    );

    if (row) {
        configureChild(row);
    }
}

/**
 * Botón "Editar" del encabezado: en borrador abre la configuración de KR;
 * activo con un solo KR abre directo la edición de su meta; en otro caso
 * desplaza a "Resultados por KPI" para elegir cuál editar.
 */
function onEdit() {
    if (props.objective.lifecycle_status === 'draft') {
        configureSelf();

        return;
    }

    if (
        props.objective.lifecycle_status === 'active' &&
        props.keyResults.length === 1
    ) {
        editingKr.value = props.keyResults[0];
        editGoalOpen.value = true;

        return;
    }

    document
        .getElementById('okr-kpi-results')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/** Abre la edición de meta del KR indicado. */
function openEditKr(kr: any) {
    editingKr.value = kr;
    editGoalOpen.value = true;
}

/**
 * Abre la carga de colocación. Sin semana explícita elige la primera pendiente
 * hasta la semana actual; si no, la semana actual; si no, la primera.
 */
function openUpload(week?: any) {
    const weeks = props.objective.placement_weeks ?? [];
    const current = props.objective.current_week || 1;
    uploadWeek.value =
        week ??
        weeks.find((w: any) => !w.uploaded && w.week_number <= current) ??
        weeks.find((w: any) => w.week_number === current) ??
        weeks[0] ??
        null;
    uploadOpen.value = true;
}

/** Recalcula el avance con Reportería (POST /okr/{id}/refresh); muestra el primer error si falla. */
function refresh() {
    refreshing.value = true;
    router.post(
        `/okr/${props.objective.id}/refresh`,
        {},
        {
            preserveScroll: true,
            onError: (e) =>
                Swal.fire({
                    icon: 'error',
                    title: 'No se pudo recalcular',
                    text: String(Object.values(e)[0] ?? ''),
                }),
            onFinish: () => {
                refreshing.value = false;
            },
        },
    );
}

/** Confirma y elimina (cancela) el OKR vía DELETE /okr/{id}; histórico y documentos se conservan. */
async function destroyObjective() {
    const r = await dangerDialog({
        icon: 'warning',
        title: '¿Eliminar este OKR?',
        text: 'Se cancelará y quedará fuera del tablero activo. El histórico, evidencias y documentos se conservan.',
        confirmText: 'Sí, eliminar',
    });

    if (r.isConfirmed) {
        router.delete(`/okr/${props.objective.id}`);
    }
}

/** Confirma y quita un KR de un OKR en borrador (DELETE /okr/{id}/key-results/{kr}). */
async function removeDraftKr(kr: any) {
    const r = await dangerDialog({
        icon: 'warning',
        title: `¿Quitar el KR "${kr.kpi.name}"?`,
        confirmText: 'Quitar',
    });

    if (r.isConfirmed) {
        router.delete(`/okr/${props.objective.id}/key-results/${kr.id}`, {
            preserveScroll: true,
        });
    }
}

const page = usePage();
/**
 * Muestra los mensajes flash del servidor: warning como alerta (con el éxito
 * como título si viene), success como toast. `immediate` cubre el flash de la
 * carga inicial.
 */
watch(
    () => (page.props as any).flash,
    (flash) => {
        if (flash?.warning) {
            Swal.fire({
                icon: 'warning',
                title: 'Atención',
                text: flash.warning,
            });
        } else if (flash?.success) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: flash.success,
                timer: 3000,
                showConfirmButton: false,
            });
        }
    },
    { immediate: true },
);

/** Desplaza suavemente la vista al elemento con el id indicado. */
function scrollTo(id: string) {
    document
        .getElementById(id)
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

/**
 * Acceso directo desde "Seguimiento por gestor" (?panel=letter|warning|documents|history):
 * abre la carta o el warning, o desplaza a documentos/histórico.
 */
onMounted(async () => {
    const panel = new URLSearchParams(window.location.search).get('panel');

    if (panel === 'letter') {
        letterOpen.value = true;
    } else if (panel === 'warning') {
        warningOpen.value = true;
    } else if (panel) {
        await nextTick();
        scrollTo(panel === 'documents' ? 'okr-documents' : 'okr-history');
    }
});
</script>

<template>
    <div class="w-full space-y-5 px-4 py-4 sm:px-6 sm:py-6 lg:px-8">
        <!-- Encabezado con estado y acciones del OKR -->
        <OkrTrackingHeader
            :objective="objective"
            :can-assign="canAssign"
            :can-update="canUpdate"
            :can-manage="canManage"
            :can-checkin="canCheckin"
            :can-upload-placement="canUploadPlacement"
            @letter="letterOpen = true"
            @upload="openUpload()"
            @checkin="checkInOpen = true"
            @activate="activationOpen = true"
            @edit="onEdit"
            @weights="weightsOpen = true"
            @refresh="refresh"
            @destroy="destroyObjective"
            @warning="warningOpen = true"
        />

        <button
            type="button"
            class="inline-flex items-center gap-1.5 text-xs font-bold text-primary hover:underline"
            @click="startTour('okr')"
        >
            <CircleHelp class="size-3.5" /> ¿Cómo funciona este OKR?
        </button>

        <!-- Enlace al OKR de sucursal (cuando este es individual de un gestor) -->
        <a
            v-if="objective.parent"
            :href="`/okr/${objective.parent.id}`"
            class="group flex items-center gap-2 rounded-xl border border-border bg-card px-4 py-2.5 text-xs shadow-sm transition hover:border-primary/40 hover:shadow-md"
        >
            <Target class="size-4 text-primary" />
            <span class="text-muted-foreground"
                >OKR individual ligado al objetivo general de
                <strong class="text-foreground">{{
                    objective.parent.branch ?? 'la sucursal'
                }}</strong
                >:</span
            >
            <span class="truncate font-semibold text-foreground">{{
                objective.parent.title
            }}</span>
            <span
                class="ml-auto font-bold text-primary transition group-hover:translate-x-0.5"
                >Ver sucursal →</span
            >
        </a>

        <!-- Alertas activas del OKR -->
        <div v-if="alerts.length" class="space-y-1.5">
            <div
                v-for="a in alerts"
                :key="a.id"
                class="flex items-center gap-2 rounded-xl border border-amber-500/20 bg-amber-500/5 px-3 py-2 text-xs font-semibold text-amber-700 dark:text-amber-300"
            >
                <Bell class="size-3.5 shrink-0" /> {{ a.message }}
            </div>
        </div>

        <!-- Filtros de alcance (sucursal/periodo) -->
        <OkrScopeFilters
            :objective="objective"
            :branches="filterBranches"
            :periods="filterPeriods"
        />

        <!-- §1 Qué se evalúa, a quién y qué falta · §2 base/meta/avance/cumplimiento -->
        <OkrEvaluationContext
            :objective="objective"
            :key-results="keyResults"
            :can-assign="canAssign"
            :can-update="canUpdate"
            @activate="activationOpen = true"
            @configure="configureSelf"
            @letter="letterOpen = true"
            @upload="openUpload()"
            @documents="scrollTo('okr-documents')"
        />

        <!-- Resumen del objetivo (solo con KR) -->
        <OkrObjectiveSummary
            v-if="keyResults.length"
            :objective="objective"
            :key-results="keyResults"
        />

        <!-- §3 Resultados por KPI -->
        <section
            id="okr-kpi-results"
            class="rounded-2xl border border-border bg-card shadow-sm"
        >
            <header
                class="flex items-center gap-2 border-b border-border px-4 py-3"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-300"
                    ><LineChart class="size-4"
                /></span>
                <div>
                    <p class="text-sm font-bold text-foreground">
                        Resultados por KPI
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        Actual contra lo esperado a la fecha; la proyección
                        aparece solo con datos semanales suficientes.
                    </p>
                </div>
            </header>
            <div class="p-4">
                <OkrKpiResultsTable
                    v-if="keyResults.length"
                    :key-results="keyResults"
                    :lifecycle="objective.lifecycle_status"
                    :can-update="canUpdate"
                    @edit="openEditKr"
                    @remove="removeDraftKr"
                />
                <div
                    v-else
                    class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-amber-500/40 bg-amber-500/5 p-6 text-center"
                >
                    <AlertTriangle class="size-6 text-amber-600" />
                    <p class="text-sm font-semibold text-foreground">
                        Este OKR no tiene Key Results
                    </p>
                    <p class="max-w-md text-xs text-muted-foreground">
                        Captura su KPI, línea base y meta propias. Nunca se
                        copia la meta de la sucursal.
                    </p>
                    <Button
                        v-if="
                            canUpdate && objective.lifecycle_status === 'draft'
                        "
                        size="sm"
                        class="mt-1 rounded-lg"
                        @click="configureSelf"
                        >Configurar KR</Button
                    >
                </div>
            </div>
        </section>

        <!-- §4 Seguimiento por gestor -->
        <OkrGestorTable
            v-if="isBranch && (objective.gestor_tracking ?? []).length"
            :tracking="objective.gestor_tracking"
            :contributions="objective.contributions ?? []"
            :current-week="objective.current_week"
            :duration-weeks="objective.duration_weeks"
            :can-update="canUpdate"
            @configure="configureChild"
        />

        <!-- §5 Colocación semanal con archivos y desglose -->
        <section
            v-if="hasPlacement"
            id="okr-placement"
            class="rounded-2xl border border-border bg-card shadow-sm"
        >
            <header
                class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3"
            >
                <div class="flex items-center gap-2">
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-300"
                        ><CalendarRange class="size-4"
                    /></span>
                    <div>
                        <p class="text-sm font-bold text-foreground">
                            Colocación semanal
                        </p>
                        <p class="text-[11px] text-muted-foreground">
                            {{
                                isBranch
                                    ? 'Un solo archivo por semana alimenta a la sucursal y a cada gestor; los movimientos repetidos se cuentan una vez.'
                                    : 'Se toma del archivo semanal de la sucursal: solo los movimientos de este gestor.'
                            }}
                        </p>
                    </div>
                </div>
                <Button
                    v-if="canUploadPlacement"
                    data-tour="okr-placement-upload"
                    class="h-9 gap-1.5 rounded-xl shadow-md transition hover:-translate-y-0.5"
                    @click="openUpload()"
                    ><Upload class="size-4" /> Cargar colocación</Button
                >
            </header>
            <div class="p-4">
                <OkrPlacementWeeksTable
                    :objective-id="objective.id"
                    :weeks="objective.placement_weeks"
                    :current-week="objective.current_week"
                    :can-upload="canUploadPlacement"
                    :is-child="!isBranch"
                    @upload="openUpload"
                />
            </div>
        </section>

        <!-- §6 Documentos: carta, warnings, firmas -->
        <div id="okr-documents">
            <OkrDocumentsPanel
                :objective="objective"
                :warnings="warnings"
                :can-assign="canAssign"
                :can-update="canUpdate"
                @letter="letterOpen = true"
                @warning="warningOpen = true"
            />
        </div>

        <!-- §7 Evidencias, check-ins e histórico -->
        <OkrEvidencePanel
            :objective="objective"
            :evidences="evidences"
            :check-ins="checkIns"
            :can-upload-evidence="canUploadEvidence"
            :can-checkin="canCheckin"
            @evidence="evidenceOpen = true"
            @checkin="checkInOpen = true"
        />

        <!-- Histórico semanal y bitácora de auditoría -->
        <div id="okr-history">
            <OkrWeeklyHistory :key-results="keyResults" />
        </div>

        <OkrAuditTrail :logs="auditLogs" />

        <!-- Aviso flotante de recálculo y diálogos de la pantalla -->
        <p
            v-if="refreshing"
            class="fixed right-4 bottom-4 z-40 rounded-full bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-lg"
        >
            Recalculando con Reportería…
        </p>

        <OkrActivationDialog
            v-model:open="activationOpen"
            :objective-id="objective.id"
            @configure="configureFromActivation"
        />
        <OkrCompleteKrDialog
            v-model:open="completeOpen"
            :target="completeTarget"
            :kpis="kpis"
        />
        <OkrCommitmentLetterDialog
            v-model:open="letterOpen"
            :objective-id="objective.id"
            :scope-type="objective.scope_type"
            :branch-id="objective.branch?.id ?? null"
            :can-issue="canAssign"
        />
        <OkrWarningDialog
            v-model:open="warningOpen"
            :objective-id="objective.id"
            :can-issue="canAssign"
        />
        <OkrPlacementUploadDialog
            v-model:open="uploadOpen"
            :objective-id="objective.id"
            :scope-type="objective.scope_type"
            :week="uploadWeek"
            :has-children="(objective.children ?? []).length > 0"
        />
        <OkrEditGoalDialog
            v-model:open="editGoalOpen"
            :objective-id="objective.id"
            :key-result="editingKr"
        />
        <OkrWeightsDialog
            v-model:open="weightsOpen"
            :objective-id="objective.id"
            :key-results="keyResults"
        />
        <OkrCheckInDialog
            v-model:open="checkInOpen"
            :objective-id="objective.id"
            :key-results="keyResults"
            :users="users"
            :already-checked-in-this-week="alreadyCheckedInThisWeek"
        />
        <OkrEvidenceUploadDialog
            v-model:open="evidenceOpen"
            :objective-id="objective.id"
        />
    </div>
</template>
