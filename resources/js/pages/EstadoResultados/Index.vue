<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BarChart3,
    Calculator,
    FolderInput,
    Gauge,
    Inbox,
    Landmark,
    ListChecks,
    ListTree,
    Scale,
    ScanSearch,
    ShieldCheck,
    Shuffle,
    Lock,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import AppPageHeader from '@/components/app/AppPageHeader.vue';
import AdjustmentsPanel from '@/components/estado-resultados/AdjustmentsPanel.vue';
import ClassifyDialog from '@/components/estado-resultados/ClassifyDialog.vue';
import CloseRoute from '@/components/estado-resultados/CloseRoute.vue';
import ConciliationSummary from '@/components/estado-resultados/ConciliationSummary.vue';
import ControlPanel from '@/components/estado-resultados/ControlPanel.vue';
import DatasetSkeleton from '@/components/estado-resultados/DatasetSkeleton.vue';
import ErCommandBar from '@/components/estado-resultados/ErCommandBar.vue';
import ErTabs from '@/components/estado-resultados/ErTabs.vue';
import type { ErTab } from '@/components/estado-resultados/ErTabs.vue';
import IndicatorsPanel from '@/components/estado-resultados/IndicatorsPanel.vue';
import KpiStrip from '@/components/estado-resultados/KpiStrip.vue';
import MovementDetailSheet from '@/components/estado-resultados/MovementDetailSheet.vue';
import { resolveFilter } from '@/components/estado-resultados/movementFilters';
import MovementsTable from '@/components/estado-resultados/MovementsTable.vue';
import OutsidePanel from '@/components/estado-resultados/OutsidePanel.vue';
import ReportStatusBar from '@/components/estado-resultados/ReportStatusBar.vue';
import ReviewQueue from '@/components/estado-resultados/ReviewQueue.vue';
import SourcesPanel from '@/components/estado-resultados/SourcesPanel.vue';
import StatementTable from '@/components/estado-resultados/StatementTable.vue';
import SummaryView from '@/components/estado-resultados/SummaryView.vue';
import { TONE_CLS } from '@/components/estado-resultados/types';
import type {
    Movement,
    Permissions,
    TreatmentMeta,
    UploadRules,
} from '@/components/estado-resultados/types';
import ValidationPanel from '@/components/estado-resultados/ValidationPanel.vue';
import VersionHistorySheet from '@/components/estado-resultados/VersionHistorySheet.vue';
import { Button } from '@/components/ui/button';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useCloseRoute } from '@/composables/estado-resultados/useCloseRoute';
import type { CloseStep } from '@/composables/estado-resultados/useCloseRoute';
import { useErActions } from '@/composables/estado-resultados/useErActions';
import { useErDataset } from '@/composables/estado-resultados/useErDataset';
import { useErGovernance } from '@/composables/estado-resultados/useErGovernance';
import { useErNavigation } from '@/composables/estado-resultados/useErNavigation';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Estado de Resultados', href: '/estado-resultados' },
        ],
    },
});

const props = defineProps<{
    periods: Array<{
        id: number;
        code: string;
        label: string;
        start: string;
        end: string;
    }>;
    initialPeriodId: number;
    initialScope: string;
    slots: Array<{ key: string; label: string }>;
    treatments: TreatmentMeta[];
    adjustmentStates: string[];
    can: Permissions;
    toleranceDays: number;
    reportStatuses?: Array<{ key: string; label: string }>;
    uploadRules?: UploadRules;
}>();

const initialVersion = (() => {
    if (typeof window === 'undefined') {
        return null;
    }

    const v = Number(
        new URLSearchParams(window.location.search).get('version'),
    );

    return Number.isInteger(v) && v > 0 ? v : null;
})();

// ── Estado (refs y computeds antes de cualquier watch) ───────────────────
const data = useErDataset({
    periodId: props.initialPeriodId,
    scope: props.initialScope,
    version: initialVersion,
});
const { periodId, scope, scopes, dataset, loading, loadError, version } = data;
const gov = useErGovernance({
    url: data.url,
    dataset,
    viewVersion: data.viewVersion,
    periodId,
    loadDataset: data.loadDataset,
    can: props.can,
});
const report = gov.report;
const lockReason = gov.lockReason;
/** Motivos de "preliminar" según el backend (o la ruta de cierre si no llega). */
const preliminaryReasons = computed<string[]>(() =>
    report.value
        ? report.value.preliminary
            ? report.value.preliminary_reasons
            : []
        : route.blockers.value,
);
const historyOpen = ref(false);
const route = useCloseRoute(dataset);
const nav = useErNavigation();
const actions = useErActions({
    url: data.url,
    scope,
    scopeQuery: data.scopeQuery,
    loadDataset: data.loadDataset,
    can: props.can,
    toleranceDays: props.toleranceDays,
    blockers: () => preliminaryReasons.value,
    lockReason: () => lockReason.value,
    uploadRules: props.uploadRules ?? null,
});

/** Permisos efectivos: en una versión de solo lectura nadie modifica nada. */
const canWrite = computed(() => ({
    cargar: props.can.cargar && !lockReason.value,
    clasificar: props.can.clasificar && !lockReason.value,
    ajustes: props.can.ajustes && !lockReason.value,
}));
const hiddenNote = computed(() => {
    const r = dataset.value?.meta.redactions;

    if (!r || (!r.nomina && !r.dg)) {
        return null;
    }

    const n = r.hidden_movements.nomina + r.hidden_movements.dg;

    return n
        ? `${n} movimiento(s) de ${[r.nomina ? 'nómina' : null, r.dg ? 'DG' : null].filter(Boolean).join(' y ')} ocultos por permisos`
        : null;
});
const movementByFp = computed(
    () =>
        new Map(
            (dataset.value?.movements ?? []).map((m) => [m.fingerprint, m]),
        ),
);
const lookup = (fp: string) => movementByFp.value.get(fp) ?? null;

const table = ref<InstanceType<typeof MovementsTable> | null>(null);
const detailOpen = ref(false);
const detailMovement = ref<Movement | null>(null);
const classifyOpen = ref(false);
const classifyItems = ref<Movement[]>([]);

const toneOf = (t: string) => {
    const meta = props.treatments.find((x) => x.key === t);

    return TONE_CLS[meta?.tone ?? 'muted'] ?? TONE_CLS.muted;
};

const sectionTitle = (key: string) => {
    const b = dataset.value?.statement.find((x) => x.key === key);

    return b?.type === 'section' ? b.title.toLowerCase() : key;
};
const resolve = (key: string) =>
    resolveFilter(key, { treatments: props.treatments, sectionTitle });

const tabs = computed<ErTab[]>(() => {
    const d = dataset.value;
    const pend = d?.conciliation.pendientes.count ?? 0;
    const failed = d?.checks.filter((c) => !c.ok).length ?? 0;
    const sourcesStep = route.steps.value.find((s) => s.key === 'fuentes');
    const noInfo =
        d?.adjustments.filter((a) => a.state === 'sin_informacion' && a.affects)
            .length ?? 0;

    return [
        { key: 'resumen', label: 'Resumen', icon: BarChart3 },
        { key: 'estado', label: 'Estado de resultados', icon: ListTree },
        { key: 'conciliacion', label: 'Movimientos', icon: Scale },
        {
            key: 'revision',
            label: 'Por revisar',
            icon: ListChecks,
            badge: pend || null,
            tone: 'warn',
        },
        { key: 'fuera', label: 'Fuera del ER', icon: Shuffle },
        {
            key: 'ajustes',
            label: 'Ajustes',
            icon: Calculator,
            badge: noInfo || null,
            tone: 'warn',
        },
        { key: 'indicadores', label: 'Indicadores', icon: Gauge },
        {
            key: 'fuentes',
            label: 'Fuentes',
            icon: FolderInput,
            badge: sourcesStep && !sourcesStep.done ? '!' : null,
            tone: 'warn',
        },
        {
            key: 'validacion',
            label: 'Validación',
            icon: ScanSearch,
            badge: d?.validation
                ? d.validation.unresolved.length +
                      d.validation.failed_checks.length || null
                : null,
            tone: d?.validation?.status === 'con_diferencias' ? 'bad' : 'warn',
        },
        {
            key: 'control',
            label: 'Control',
            icon: ShieldCheck,
            badge: failed || null,
            tone: 'bad',
        },
    ];
});

// Al recalcular, el detalle abierto se refresca con la versión nueva del movimiento.
watch(version, () => {
    const fp = detailMovement.value?.fingerprint;

    if (fp) {
        detailMovement.value =
            dataset.value?.movements.find((m) => m.fingerprint === fp) ?? null;
    }
});
watch(periodId, () => {
    table.value?.clearSelection();
    nav.selectedStep.value = null;
    nav.movementFilter.value = null;
});

// ── Interacciones ────────────────────────────────────────────────────────
function onRouteStep(s: CloseStep): void {
    nav.go(s.go.tab, s.go.filter);
}

function openDetail(m: Movement): void {
    detailMovement.value = m;
    detailOpen.value = true;
}

function openClassify(fingerprints: string[]): void {
    const set = new Set(fingerprints);
    classifyItems.value = (dataset.value?.movements ?? []).filter((m) =>
        set.has(m.fingerprint),
    );
    detailOpen.value = false;

    if (classifyItems.value.length) {
        classifyOpen.value = true;
    }
}

async function submitClassify(payload: {
    treatment: string;
    notes: string;
}): Promise<void> {
    const ok = await actions.classify(
        classifyItems.value.map((m) => m.fingerprint),
        payload,
    );

    if (ok) {
        classifyOpen.value = false;
        table.value?.clearSelection();
    }
}

async function accept(fingerprints: string[], ask: boolean): Promise<void> {
    if (await actions.acceptSuggestions(fingerprints, ask)) {
        table.value?.clearSelection();
    }
}

function openByFingerprint(fp: string): void {
    const m = lookup(fp);

    if (m) {
        openDetail(m);
    } else {
        // Oculto por permisos o fuera del alcance: se explica, no se falla en silencio.
        actions.showError(
            new Error(
                'Ese movimiento no está disponible en esta vista (puede estar fuera del alcance elegido o restringido por permisos).',
            ),
            'No se puede abrir el movimiento',
        );
    }
}

async function openHistory(): Promise<void> {
    historyOpen.value = true;
    await gov.loadHistory();
}

function onVersionPick(v: number | null): void {
    historyOpen.value = false;
    gov.viewVersion(v);
}

const attemptsUrl = computed(() =>
    props.can.cargar ? data.url('/cargas/intentos') : null,
);

const historyUrl = (fp: string) =>
    data.url(`/historial-clasificacion?fingerprint=${encodeURIComponent(fp)}`);
</script>

<template>
    <Head title="Estado de Resultados" />
    <TooltipProvider :delay-duration="150">
        <div class="space-y-4 p-4 md:p-6">
            <AppPageHeader
                kicker="Análisis financiero"
                title="Estado de Resultados"
                subtitle="Del banco, el ERP y la Radiografía a los tres EBITDA, el EBIT, el EBT y la utilidad neta. Cada cifra se puede abrir para ver de dónde sale."
            >
                <template #icon>
                    <Landmark class="size-5" />
                </template>
            </AppPageHeader>

            <ErCommandBar
                v-if="periods.length"
                v-model:period-id="periodId"
                v-model:scope="scope"
                :periods="periods"
                :scopes="scopes"
                :dataset="dataset"
                :loading="loading"
                :processing="actions.processing.value"
                :can="can"
                :complete="!preliminaryReasons.length"
                :blockers="preliminaryReasons"
                :lock-reason="lockReason"
                @process="actions.process()"
                @export="actions.exportFile"
            />

            <div
                v-if="!periods.length"
                class="flex flex-col items-center gap-2 rounded-2xl border border-dashed bg-card py-20 text-center"
            >
                <Landmark class="size-10 text-muted-foreground" />
                <p class="font-semibold">Todavía no hay periodos mensuales</p>
                <p class="max-w-md text-sm text-muted-foreground">
                    Crea el periodo del mes en el módulo Periodos; después
                    podrás generar aquí su Estado de Resultados.
                </p>
            </div>

            <template v-else>
                <div
                    v-if="loadError"
                    role="alert"
                    class="flex items-start gap-3 rounded-2xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"
                >
                    <AlertTriangle class="mt-0.5 size-5 shrink-0" />
                    <div>
                        <p class="font-bold">
                            No se pudo calcular el Estado de Resultados
                        </p>
                        <p class="mt-0.5">{{ loadError }}</p>
                        <Button
                            size="sm"
                            variant="outline"
                            class="mt-3 rounded-lg"
                            @click="data.loadDataset()"
                        >
                            Intentar de nuevo
                        </Button>
                    </div>
                </div>

                <DatasetSkeleton v-if="!dataset && loading" />

                <Transition name="er-fade" mode="out-in">
                    <div
                        v-if="dataset"
                        :key="dataset.meta.period.id + dataset.meta.scope.key"
                        class="space-y-4 transition-opacity"
                        :class="loading ? 'pointer-events-none opacity-60' : ''"
                        :aria-busy="loading"
                    >
                        <ReportStatusBar
                            v-if="report"
                            :report="report"
                            :can-approve="!!can.aprobar"
                            :approving="gov.approving.value"
                            :reopening="gov.reopening.value"
                            :viewing-historic="gov.viewingHistoric.value"
                            @approve="gov.approve"
                            @reopen="gov.openNewVersion"
                            @history="openHistory"
                            @back-to-current="gov.viewVersion(null)"
                            @go="(t) => nav.go(t)"
                        />

                        <p
                            v-if="dataset.meta.redactions?.message"
                            class="flex items-start gap-2 rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"
                            role="note"
                        >
                            <Lock class="mt-0.5 size-4 shrink-0" />
                            <span
                                >{{ dataset.meta.redactions.message }} Los
                                totales y los resultados sí incluyen esos
                                importes.</span
                            >
                        </p>

                        <CloseRoute
                            v-if="!lockReason"
                            :steps="route.steps.value"
                            :done-count="route.doneCount.value"
                            @go="onRouteStep"
                        />

                        <KpiStrip
                            :dataset="dataset"
                            :active-step="nav.selectedStep.value"
                            @select="nav.selectStep"
                        />

                        <span id="er-tabs-anchor" class="block scroll-mt-2" />
                        <ErTabs v-model="nav.tab.value" :tabs="tabs" />

                        <Transition name="er-fade" mode="out-in">
                            <section
                                v-if="nav.tab.value === 'resumen'"
                                key="resumen"
                                role="tabpanel"
                            >
                                <SummaryView
                                    v-model:selected="nav.selectedStep.value"
                                    :dataset="dataset"
                                    @open-statement="nav.openStatement"
                                    @open-movements="nav.openMovements"
                                />
                            </section>

                            <section
                                v-else-if="nav.tab.value === 'estado'"
                                key="estado"
                                role="tabpanel"
                            >
                                <StatementTable
                                    :blocks="dataset.statement"
                                    :movements="dataset.movements"
                                    :highlight="nav.statementFocus.value"
                                    @open-movements="nav.openMovements"
                                />
                            </section>

                            <section
                                v-else-if="nav.tab.value === 'conciliacion'"
                                key="conciliacion"
                                role="tabpanel"
                                class="space-y-4"
                            >
                                <ConciliationSummary
                                    :c="dataset.conciliation"
                                    :movements="dataset.movements"
                                    :active-filter="nav.movementFilter.value"
                                    @filter="
                                        (k) =>
                                            (nav.movementFilter.value =
                                                nav.movementFilter.value === k
                                                    ? null
                                                    : k)
                                    "
                                />
                                <MovementsTable
                                    ref="table"
                                    v-model:filter="nav.movementFilter.value"
                                    :movements="dataset.movements"
                                    :can-classify="can.clasificar"
                                    :lock-reason="lockReason"
                                    :hidden-note="hiddenNote"
                                    :dimension-catalog="dataset.dimensions"
                                    :tone-of="toneOf"
                                    :resolve="resolve"
                                    @open="openDetail"
                                    @classify="openClassify"
                                    @accept-suggestions="accept"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Haz clic en un movimiento para ver por qué
                                    suma o no. Los tramos de la barra filtran la
                                    tabla.
                                </p>
                            </section>

                            <section
                                v-else-if="nav.tab.value === 'revision'"
                                key="revision"
                                role="tabpanel"
                            >
                                <ReviewQueue
                                    :movements="dataset.movements"
                                    :c="dataset.conciliation"
                                    :can-classify="canWrite.clasificar"
                                    :lock-reason="lockReason"
                                    :accepting="actions.accepting.value"
                                    @accept="accept"
                                    @classify="openClassify"
                                    @open="openDetail"
                                    @show-all="nav.openMovements('pendientes')"
                                />
                            </section>

                            <section
                                v-else-if="nav.tab.value === 'fuera'"
                                key="fuera"
                                role="tabpanel"
                            >
                                <OutsidePanel
                                    :outside="dataset.outside"
                                    @open-movements="nav.openMovements"
                                />
                            </section>

                            <section
                                v-else-if="nav.tab.value === 'ajustes'"
                                key="ajustes"
                                role="tabpanel"
                            >
                                <AdjustmentsPanel
                                    :adjustments="dataset.adjustments"
                                    :can-edit="canWrite.ajustes"
                                    :lock-reason="lockReason"
                                    :saving-key="actions.savingAdjustment.value"
                                    :logs="actions.adjustmentLogs.value"
                                    :logs-loading="actions.logsLoading.value"
                                    @save="actions.saveAdjustment"
                                    @load-logs="actions.loadLogs"
                                />
                            </section>

                            <section
                                v-else-if="nav.tab.value === 'indicadores'"
                                key="indicadores"
                                role="tabpanel"
                            >
                                <IndicatorsPanel
                                    :financial="dataset.indicators.financial"
                                    :informative="
                                        dataset.indicators.informative
                                    "
                                />
                            </section>

                            <section
                                v-else-if="nav.tab.value === 'fuentes'"
                                key="fuentes"
                                role="tabpanel"
                            >
                                <SourcesPanel
                                    :sources="dataset.sources"
                                    :can-upload="can.cargar"
                                    :uploading-slot="
                                        actions.uploadingSlot.value
                                    "
                                    :upload-rules="uploadRules"
                                    :lock-reason="lockReason"
                                    :attempts-url="attemptsUrl"
                                    :refresh-key="
                                        version + actions.uploadSeq.value
                                    "
                                    @upload="actions.upload"
                                />
                            </section>

                            <section
                                v-else-if="nav.tab.value === 'validacion'"
                                key="validacion"
                                role="tabpanel"
                            >
                                <ValidationPanel
                                    v-if="dataset.validation"
                                    :validation="dataset.validation"
                                    @open="openByFingerprint"
                                />
                                <p
                                    v-else
                                    class="rounded-2xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground"
                                >
                                    Esta versión se aprobó antes de que
                                    existiera la pantalla de validación: no trae
                                    ese detalle.
                                </p>
                            </section>

                            <section v-else key="control" role="tabpanel">
                                <ControlPanel :dataset="dataset" />
                            </section>
                        </Transition>
                    </div>
                </Transition>

                <div
                    v-if="!dataset && !loading && !loadError"
                    class="flex flex-col items-center gap-2 rounded-2xl border border-dashed bg-card py-16 text-center"
                >
                    <Inbox class="size-9 text-muted-foreground/60" />
                    <p class="font-semibold">Elige un periodo para empezar</p>
                </div>
            </template>
        </div>

        <MovementDetailSheet
            v-model:open="detailOpen"
            :movement="detailMovement"
            :history-url="historyUrl"
            :can-classify="can.clasificar"
            :lock-reason="lockReason"
            :lookup="lookup"
            :tone-of="toneOf"
            @classify="openClassify"
            @open-movement="openDetail"
            @accept-suggestion="(fp) => accept([fp], false)"
        />
        <ClassifyDialog
            v-model:open="classifyOpen"
            :items="classifyItems"
            :treatments="treatments"
            :saving="actions.classifying.value"
            @submit="submitClassify"
        />
        <VersionHistorySheet
            v-model:open="historyOpen"
            :history="gov.history.value"
            :loading="gov.historyLoading.value"
            :viewing="data.viewVersion.value"
            :report="report"
            @view="onVersionPick"
        />
    </TooltipProvider>
</template>

<style>
/* Sólo lo que Tailwind no cubre: transiciones de Vue y la animación de entrada de la cascada. */
.er-fade-enter-active,
.er-fade-leave-active {
    transition:
        opacity 180ms var(--ease-out, ease),
        transform 180ms var(--ease-out, ease);
}
.er-fade-enter-from,
.er-fade-leave-to {
    opacity: 0;
    transform: translateY(4px);
}
.er-slide-enter-active,
.er-slide-leave-active {
    transition:
        opacity 200ms var(--ease-out, ease),
        transform 200ms var(--ease-out, ease);
}
.er-slide-enter-from {
    opacity: 0;
    transform: translateX(16px);
}
.er-slide-leave-to {
    opacity: 0;
    transform: translateX(-16px);
}
@keyframes er-grow {
    from {
        transform: scaleY(0);
    }
    to {
        transform: scaleY(1);
    }
}
.er-grow {
    transform-origin: bottom;
    animation: er-grow 620ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
}
.er-grow.er-grow-down {
    transform-origin: top;
}
@media (prefers-reduced-motion: reduce) {
    .er-grow,
    .er-fade-enter-active,
    .er-fade-leave-active,
    .er-slide-enter-active,
    .er-slide-leave-active {
        animation: none;
        transition: none;
    }
}
</style>
