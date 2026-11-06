<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BarChart3,
    Calculator,
    ClipboardCheck,
    FileSpreadsheet,
    FileText,
    Gauge,
    Landmark,
    ListTree,
    Loader2,
    RefreshCw,
    Scale,
    ShieldCheck,
    Shuffle,
} from 'lucide-vue-next';
import Swal from 'sweetalert2';
import { computed, onMounted, ref, watch } from 'vue';
import AppPageHeader from '@/components/app/AppPageHeader.vue';
import AdjustmentsPanel from '@/components/estado-resultados/AdjustmentsPanel.vue';
import ClassifyDialog from '@/components/estado-resultados/ClassifyDialog.vue';
import ConciliationSummary from '@/components/estado-resultados/ConciliationSummary.vue';
import ControlPanel from '@/components/estado-resultados/ControlPanel.vue';
import DatasetSkeleton from '@/components/estado-resultados/DatasetSkeleton.vue';
import EbitdaWaterfall from '@/components/estado-resultados/EbitdaWaterfall.vue';
import IndicatorsPanel from '@/components/estado-resultados/IndicatorsPanel.vue';
import KpiStrip from '@/components/estado-resultados/KpiStrip.vue';
import MovementDetailSheet from '@/components/estado-resultados/MovementDetailSheet.vue';
import MovementsTable from '@/components/estado-resultados/MovementsTable.vue';
import OutsidePanel from '@/components/estado-resultados/OutsidePanel.vue';
import RadiographyReconciliationCard from '@/components/estado-resultados/RadiographyReconciliationCard.vue';
import SourcesPanel from '@/components/estado-resultados/SourcesPanel.vue';
import StatementTable from '@/components/estado-resultados/StatementTable.vue';
import {
    ApiError,
    apiJson,
    mxn,
    TONE_CLS,
} from '@/components/estado-resultados/types';
import type {
    AdjustmentState,
    Dataset,
    Movement,
    Permissions,
    TreatmentMeta,
} from '@/components/estado-resultados/types';
import { Button } from '@/components/ui/button';
import { TooltipProvider } from '@/components/ui/tooltip';
import { useConfirm } from '@/composables/useConfirm';
import { useToast } from '@/composables/useToast';
import { downloadFile } from '@/lib/downloadOverlay';

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
}>();

const page = usePage();
const toast = useToast();
const { confirm } = useConfirm();
const appBase = (page.props as { base?: string }).base ?? '';

// ── Estado ───────────────────────────────────────────────────────────────
const periodId = ref<number>(props.initialPeriodId);
const scope = ref<string>(props.initialScope || 'CONSOLIDADO');
const scopes = ref<Array<{ key: string; label: string }>>([
    { key: 'CONSOLIDADO', label: 'Consolidado MR LANA' },
]);
const dataset = ref<Dataset | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const processing = ref(false);
const uploadingSlot = ref<string | null>(null);
const tab = ref('resumen');

const detailOpen = ref(false);
const detailMovement = ref<Movement | null>(null);
const classifyOpen = ref(false);
const classifyItems = ref<Movement[]>([]);
const classifying = ref(false);
const savingAdjustment = ref<string | null>(null);
const adjustmentLogs = ref<
    Array<{
        label: string;
        old_state: string | null;
        old_amount: string | null;
        new_state: string;
        new_amount: string | null;
        notes: string | null;
        user: string | null;
        at: string;
    }>
>([]);
const logsLoading = ref(false);
const table = ref<InstanceType<typeof MovementsTable> | null>(null);

const url = (path: string) =>
    `${appBase}/estado-resultados/${periodId.value}${path}`;
const scopeQuery = () => `alcance=${encodeURIComponent(scope.value)}`;

const treatmentTone = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.treatments.map((t) => [
            t.key,
            TONE_CLS[t.tone] ?? TONE_CLS.muted,
        ]),
    ),
);
const toneOf = (t: string) => treatmentTone.value[t] ?? TONE_CLS.muted;

const TABS = [
    { key: 'resumen', label: 'Resumen', icon: BarChart3 },
    { key: 'estado', label: 'Estado de resultados', icon: ListTree },
    { key: 'conciliacion', label: 'Conciliación', icon: Scale },
    { key: 'fuera', label: 'Fuera del ER', icon: Shuffle },
    { key: 'ajustes', label: 'Ajustes contables', icon: Calculator },
    { key: 'indicadores', label: 'Indicadores', icon: Gauge },
    { key: 'control', label: 'Control', icon: ShieldCheck },
];

const pendingCount = computed(
    () => dataset.value?.conciliation.pendientes.count ?? 0,
);
const alerts = computed(() => {
    const d = dataset.value;

    if (!d) {
        return [];
    }

    const list: Array<{ tone: 'warn' | 'bad'; text: string }> = [];

    if (d.sources.procesamiento.unprocessed) {
        list.push({
            tone: 'warn',
            text: 'Hay archivos cargados sin procesar: presiona "Procesar conciliación" para conciliar contra ERP y clasificar.',
        });
    }

    if (d.sources.procesamiento.stale_erp) {
        list.push({
            tone: 'warn',
            text: 'El ERP del periodo cambió después del último procesamiento. Vuelve a procesar para conciliar contra el ERP vigente.',
        });
    }

    if (d.conciliation.pendientes.count > 0) {
        list.push({
            tone: 'warn',
            text: `${d.conciliation.pendientes.count} movimiento(s) por ${mxn(d.conciliation.pendientes.amount)} requieren revisión y NO están sumados. Los EBITDA corporativo y final pueden cambiar al clasificarlos.`,
        });
    }

    const failed = d.checks.filter((c) => !c.ok);

    if (failed.length) {
        list.push({
            tone: 'bad',
            text: `${failed.length} validación(es) matemática(s) no cuadran: ${failed.map((c) => c.label).join('; ')}.`,
        });
    }

    return list;
});

// ── Carga del dataset ────────────────────────────────────────────────────
let requestId = 0;
async function loadDataset(): Promise<void> {
    if (!periodId.value) {
        return;
    }

    const current = ++requestId;
    loading.value = true;
    loadError.value = null;

    try {
        const res = await apiJson<{
            dataset: Dataset;
            scopes: Array<{ key: string; label: string }>;
        }>(url(`/dataset?${scopeQuery()}`));

        if (current !== requestId) {
            return;
        }

        dataset.value = res.dataset;
        scopes.value = res.scopes;

        if (detailMovement.value) {
            detailMovement.value =
                res.dataset.movements.find(
                    (m) => m.fingerprint === detailMovement.value?.fingerprint,
                ) ?? null;
        }
    } catch (e) {
        if (current === requestId) {
            loadError.value =
                e instanceof Error
                    ? e.message
                    : 'No se pudo calcular el Estado de Resultados.';
            dataset.value = null;
        }
    } finally {
        if (current === requestId) {
            loading.value = false;
        }
    }
}

watch(periodId, () => {
    scope.value = 'CONSOLIDADO';
    table.value?.clearSelection();
    loadDataset();
});
watch(scope, () => loadDataset());
onMounted(loadDataset);

// ── Acciones ─────────────────────────────────────────────────────────────
function showError(e: unknown, title = 'No se pudo completar la acción'): void {
    const err =
        e instanceof ApiError
            ? e
            : new ApiError(e instanceof Error ? e.message : String(e));
    const list = err.details.length
        ? `<ul style="text-align:left;margin-top:8px;font-size:13px;max-height:240px;overflow:auto">${err.details.map((d) => `<li>• ${escapeHtml(d)}</li>`).join('')}</ul>`
        : '';
    Swal.fire({
        icon: 'error',
        title,
        html: `<p>${escapeHtml(err.message)}</p>${list}`,
    });
}

function escapeHtml(s: string): string {
    return s.replace(
        /[&<>"']/g,
        (c) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[c] as string,
    );
}

async function upload(slot: string, file: File): Promise<void> {
    uploadingSlot.value = slot;

    try {
        const form = new FormData();
        form.append('slot', slot);
        form.append('file', file);
        const res = await apiJson<{
            message: string;
            upload: { warnings: string[] };
        }>(url('/cargas'), { method: 'POST', body: form });
        await loadDataset();
        const warn = res.upload.warnings ?? [];
        const go = await Swal.fire({
            icon: warn.length ? 'warning' : 'success',
            title: 'Archivo validado y cargado',
            html: `<p>${escapeHtml(res.message)}</p>${warn.length ? `<ul style="text-align:left;margin-top:8px;font-size:13px">${warn.map((w) => `<li>• ${escapeHtml(w)}</li>`).join('')}</ul>` : ''}`,
            showCancelButton: props.can.procesar,
            confirmButtonText: props.can.procesar
                ? 'Procesar conciliación ahora'
                : 'Entendido',
            cancelButtonText: 'Después',
        });

        if (go.isConfirmed && props.can.procesar) {
            await process(false);
        }
    } catch (e) {
        showError(e, 'El archivo no se cargó');
    } finally {
        uploadingSlot.value = null;
    }
}

async function process(ask = true): Promise<void> {
    if (ask) {
        const ok = await confirm({
            title: 'Procesar conciliación',
            text: `Se conciliarán los cargos contra el ERP (tolerancia de ${props.toleranceDays} día(s)) y se aplicarán las reglas. Es idempotente: reprocesar no duplica movimientos ni borra clasificaciones manuales.`,
            icon: 'question',
            confirmText: 'Procesar',
        });

        if (!ok.isConfirmed) {
            return;
        }
    }

    processing.value = true;

    try {
        const res = await apiJson<{ message: string }>(url('/procesar'), {
            method: 'POST',
        });
        toast.success(res.message);
        await loadDataset();
    } catch (e) {
        showError(e, 'No se pudo procesar');
    } finally {
        processing.value = false;
    }
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

    if (classifyItems.value.length) {
        classifyOpen.value = true;
    }
}

async function submitClassify(payload: {
    treatment: string;
    notes: string;
}): Promise<void> {
    classifying.value = true;

    try {
        const res = await apiJson<{ message: string }>(url('/clasificar'), {
            method: 'POST',
            body: JSON.stringify({
                fingerprints: classifyItems.value.map((m) => m.fingerprint),
                ...payload,
            }),
        });
        classifyOpen.value = false;
        table.value?.clearSelection();
        toast.success(res.message);
        await loadDataset();
    } catch (e) {
        showError(e, 'No se pudo clasificar');
    } finally {
        classifying.value = false;
    }
}

async function acceptSuggestions(fingerprints: string[]): Promise<void> {
    if (!fingerprints.length) {
        return;
    }

    const ok = await confirm({
        title: `Aceptar ${fingerprints.length} sugerencia(s)`,
        text: 'Cada movimiento pendiente se clasificará con su tratamiento sugerido. Queda registrado con tu usuario y puedes corregirlo después.',
        icon: 'question',
        confirmText: 'Aceptar sugerencias',
    });

    if (!ok.isConfirmed) {
        return;
    }

    try {
        const res = await apiJson<{ message: string }>(
            url('/aceptar-sugerencias'),
            { method: 'POST', body: JSON.stringify({ fingerprints }) },
        );
        table.value?.clearSelection();
        toast.success(res.message);
        await loadDataset();
    } catch (e) {
        showError(e, 'No se pudieron aceptar las sugerencias');
    }
}

async function saveAdjustment(p: {
    concept: string;
    state: AdjustmentState;
    amount: string | null;
    notes: string | null;
}): Promise<void> {
    savingAdjustment.value = p.concept;

    try {
        const res = await apiJson<{ message: string }>(url('/ajustes'), {
            method: 'PUT',
            body: JSON.stringify({ scope: scope.value, ...p }),
        });
        toast.success(res.message);
        await loadDataset();
        adjustmentLogs.value = [];
    } catch (e) {
        showError(e, 'No se guardó el ajuste');
    } finally {
        savingAdjustment.value = null;
    }
}

async function loadLogs(): Promise<void> {
    logsLoading.value = true;

    try {
        const res = await apiJson<{ logs: typeof adjustmentLogs.value }>(
            url(`/ajustes/historial?${scopeQuery()}`),
        );
        adjustmentLogs.value = res.logs;
    } catch {
        adjustmentLogs.value = [];
    } finally {
        logsLoading.value = false;
    }
}

async function exportFile(kind: 'excel' | 'pdf'): Promise<void> {
    await downloadFile(url(`/exportar/${kind}?${scopeQuery()}`));
}

const historyUrl = (fp: string) =>
    url(`/historial-clasificacion?fingerprint=${encodeURIComponent(fp)}`);
</script>

<template>
    <Head title="Estado de Resultados" />
    <TooltipProvider :delay-duration="150">
        <div class="space-y-6 p-4 md:p-6">
            <AppPageHeader
                kicker="Análisis financiero"
                title="Estado de Resultados"
                subtitle="Tres niveles de EBITDA (sucursales → corporativo → final), conciliación de estados de cuenta contra ERP y exportes con la misma fuente de verdad."
            >
                <template #icon>
                    <Landmark class="size-5" />
                </template>
            </AppPageHeader>

            <!-- Barra de controles: fila propia para no comprimir el título -->
            <div
                class="flex flex-wrap items-center gap-2 rounded-2xl border bg-card/60 p-2 shadow-sm"
            >
                <select
                    v-model.number="periodId"
                    class="h-9 rounded-xl border bg-background px-3 text-sm font-medium"
                    aria-label="Periodo"
                >
                    <option v-for="p in periods" :key="p.id" :value="p.id">
                        {{ p.label }} ({{ p.code }})
                    </option>
                </select>
                <select
                    v-model="scope"
                    class="h-9 rounded-xl border bg-background px-3 text-sm font-medium"
                    aria-label="Alcance"
                >
                    <option v-for="s in scopes" :key="s.key" :value="s.key">
                        {{ s.label }}
                    </option>
                </select>
                <Button
                    v-if="can.procesar"
                    class="bg-teal-700 text-white hover:bg-teal-800"
                    :disabled="processing || loading || !dataset"
                    @click="process()"
                >
                    <Loader2 v-if="processing" class="animate-spin" />
                    <RefreshCw v-else />
                    Procesar conciliación
                </Button>
                <template v-if="can.exportar">
                    <Button
                        variant="outline"
                        :disabled="!dataset || loading"
                        @click="exportFile('excel')"
                    >
                        <FileSpreadsheet class="text-emerald-600" /> Excel
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="!dataset || loading"
                        @click="exportFile('pdf')"
                    >
                        <FileText class="text-rose-600" /> PDF
                    </Button>
                </template>
            </div>

            <div
                v-if="!periods.length"
                class="flex flex-col items-center gap-2 rounded-3xl border border-dashed py-20 text-center"
            >
                <Landmark class="size-10 text-muted-foreground" />
                <p class="font-semibold">No hay periodos mensuales</p>
                <p class="text-sm text-muted-foreground">
                    Crea el periodo mensual en el módulo Periodos para generar
                    su Estado de Resultados.
                </p>
            </div>

            <template v-else>
                <div
                    v-if="dataset"
                    class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                >
                    <span
                        class="rounded-full border bg-card px-2.5 py-1 font-semibold text-foreground"
                        >{{ dataset.meta.scope.label }}</span
                    >
                    <span
                        >{{ dataset.meta.period.label }} ·
                        {{ dataset.meta.period.start }} a
                        {{ dataset.meta.period.end }} · cifras en MXN</span
                    >
                    <span
                        class="rounded-full border px-2 py-0.5 font-mono"
                        :title="dataset.meta.dataset_hash"
                        >dataset
                        {{ dataset.meta.dataset_hash.slice(0, 12) }}</span
                    >
                    <span>generado {{ dataset.meta.generated_at }}</span>
                    <Loader2
                        v-if="loading"
                        class="size-3.5 animate-spin text-teal-600"
                    />
                </div>

                <div
                    v-if="loadError"
                    class="flex items-start gap-2 rounded-2xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p class="font-semibold">
                            No se pudo calcular el Estado de Resultados
                        </p>
                        <p>{{ loadError }}</p>
                        <Button
                            size="sm"
                            variant="outline"
                            class="mt-2"
                            @click="loadDataset"
                        >
                            Reintentar
                        </Button>
                    </div>
                </div>

                <DatasetSkeleton v-if="!dataset && loading" />

                <Transition name="er-fade" mode="out-in">
                    <div
                        v-if="dataset"
                        :key="dataset.meta.period.id + dataset.meta.scope.key"
                        class="space-y-6"
                        :class="
                            loading
                                ? 'pointer-events-none opacity-70 transition'
                                : 'transition'
                        "
                    >
                        <TransitionGroup
                            v-if="alerts.length"
                            tag="div"
                            name="er-fade"
                            class="space-y-2"
                        >
                            <div
                                v-for="a in alerts"
                                :key="a.text"
                                class="flex items-start gap-2 rounded-2xl border p-3 text-sm"
                                :class="
                                    a.tone === 'bad'
                                        ? 'border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200'
                                        : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200'
                                "
                            >
                                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                                <span>{{ a.text }}</span>
                            </div>
                        </TransitionGroup>

                        <SourcesPanel
                            :sources="dataset.sources"
                            :can-upload="can.cargar"
                            :uploading-slot="uploadingSlot"
                            @upload="upload"
                        />

                        <KpiStrip :dataset="dataset" />

                        <div
                            class="sticky top-0 z-10 -mx-1 flex gap-1 overflow-x-auto rounded-2xl border bg-card/95 p-1 shadow-soft-xs backdrop-blur"
                            role="tablist"
                        >
                            <button
                                v-for="t in TABS"
                                :key="t.key"
                                type="button"
                                role="tab"
                                :aria-selected="tab === t.key"
                                class="flex shrink-0 items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold transition"
                                :class="
                                    tab === t.key
                                        ? 'bg-gradient-to-r from-teal-700 to-cyan-600 text-white shadow'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                "
                                @click="tab = t.key"
                            >
                                <component :is="t.icon" class="size-4" />
                                {{ t.label }}
                                <span
                                    v-if="
                                        t.key === 'conciliacion' && pendingCount
                                    "
                                    class="rounded-full bg-amber-400 px-1.5 text-[10px] font-bold text-amber-950"
                                    >{{ pendingCount }}</span
                                >
                                <span
                                    v-if="
                                        t.key === 'control' &&
                                        dataset.checks.some((c) => !c.ok)
                                    "
                                    class="rounded-full bg-rose-500 px-1.5 text-[10px] font-bold text-white"
                                    >!</span
                                >
                            </button>
                        </div>

                        <Transition name="er-fade" mode="out-in">
                            <section
                                v-if="tab === 'resumen'"
                                key="resumen"
                                class="grid gap-4 2xl:grid-cols-[minmax(0,1fr)_400px]"
                            >
                                <div
                                    class="min-w-0 rounded-3xl border bg-card p-4 shadow-soft-xs md:p-5"
                                >
                                    <div
                                        class="mb-3 flex items-center justify-between gap-2"
                                    >
                                        <div>
                                            <p
                                                class="text-[11px] font-bold tracking-wider text-muted-foreground uppercase"
                                            >
                                                Cascada de resultados
                                            </p>
                                            <p class="text-base font-bold">
                                                De los ingresos a la utilidad
                                                neta
                                            </p>
                                        </div>
                                        <ClipboardCheck
                                            class="size-5 text-teal-600"
                                        />
                                    </div>
                                    <EbitdaWaterfall
                                        :steps="dataset.waterfall"
                                    />
                                </div>
                                <RadiographyReconciliationCard
                                    :rec="dataset.radiography_reconciliation"
                                />
                            </section>

                            <section v-else-if="tab === 'estado'" key="estado">
                                <StatementTable :blocks="dataset.statement" />
                            </section>

                            <section
                                v-else-if="tab === 'conciliacion'"
                                key="conciliacion"
                                class="space-y-4"
                            >
                                <ConciliationSummary
                                    :c="dataset.conciliation"
                                />
                                <MovementsTable
                                    ref="table"
                                    :movements="dataset.movements"
                                    :can-classify="can.clasificar"
                                    :tone-of="toneOf"
                                    @open="openDetail"
                                    @classify="openClassify"
                                    @accept-suggestions="acceptSuggestions"
                                />
                            </section>

                            <section v-else-if="tab === 'fuera'" key="fuera">
                                <OutsidePanel :outside="dataset.outside" />
                            </section>

                            <section
                                v-else-if="tab === 'ajustes'"
                                key="ajustes"
                            >
                                <AdjustmentsPanel
                                    :adjustments="dataset.adjustments"
                                    :can-edit="can.ajustes"
                                    :saving-key="savingAdjustment"
                                    :logs="adjustmentLogs"
                                    :logs-loading="logsLoading"
                                    @save="saveAdjustment"
                                    @load-logs="loadLogs"
                                />
                            </section>

                            <section
                                v-else-if="tab === 'indicadores'"
                                key="indicadores"
                            >
                                <IndicatorsPanel
                                    :financial="dataset.indicators.financial"
                                    :informative="
                                        dataset.indicators.informative
                                    "
                                />
                            </section>

                            <section v-else key="control">
                                <ControlPanel :dataset="dataset" />
                            </section>
                        </Transition>
                    </div>
                </Transition>
            </template>
        </div>

        <MovementDetailSheet
            v-model:open="detailOpen"
            :movement="detailMovement"
            :history-url="historyUrl"
            :can-classify="can.clasificar"
            :tone-of="toneOf"
            @classify="
                (fps) => {
                    detailOpen = false;
                    openClassify(fps);
                }
            "
        />
        <ClassifyDialog
            v-model:open="classifyOpen"
            :items="classifyItems"
            :treatments="treatments"
            :saving="classifying"
            @submit="submitClassify"
        />
    </TooltipProvider>
</template>

<style>
.er-fade-enter-active,
.er-fade-leave-active {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}
.er-fade-enter-from,
.er-fade-leave-to {
    opacity: 0;
    transform: translateY(4px);
}
@keyframes er-fade-up {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
.er-fade-up {
    animation: er-fade-up 360ms ease-out both;
}
@keyframes er-grow {
    from {
        transform: scaleY(0.35);
    }
    to {
        transform: scaleY(1);
    }
}
.er-grow {
    transform-origin: bottom;
    animation: er-grow 520ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
}
@media (prefers-reduced-motion: reduce) {
    .er-fade-up,
    .er-grow {
        animation: none;
    }
}
</style>
