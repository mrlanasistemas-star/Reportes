<script setup lang="ts">
/**
 * Pantalla: Comparativo web interactivo entre dos periodos de la Radiografía
 * (ruta GET /reportes-mensuales/comparativo, nombre `reportes-mensuales.comparativo`).
 *
 * Para qué sirve: comparar Periodo A (actual, `curr`) contra Periodo B (anterior, `prev`)
 * en alcance general, sucursal o gestor, con tarjetas KPI, gráficas, "mayores variaciones"
 * y tabla completa, sin recargar la página ni generar archivos/runs al cambiar selectores.
 *
 * Origen de los props: `MonthlyReportController::comparativoPage()` (Inertia::render
 * 'ReportesMensuales/ComparativePreview'): periodos/alcance iniciales (deep-link o los dos
 * meses más recientes con radiografía), lista de periodos y sucursales operativas.
 *
 * Rutas que consume:
 *  - comparativeDataUrl → GET reportes-mensuales.comparativo-data (MonthlyReportController::comparativoData();
 *    cifras de RadiografiaExportService::comparativeViewData(), la misma fuente del Excel/PDF comparativo;
 *    la respuesta incluye exportUrls para descargar).
 *  - employeesLookupUrl → GET reportes-mensuales.comparativo-employees-lookup (roster de gestores del Periodo A).
 */
import { usePage } from '@inertiajs/vue3';
import { AlertTriangle, Loader2 } from 'lucide-vue-next';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import ComparativeHero from '@/components/comparative/ComparativeHero.vue';
import ComparativeKpiCard from '@/components/comparative/ComparativeKpiCard.vue';
import ComparativeLoadingPanel from '@/components/comparative/ComparativeLoadingPanel.vue';
import ComparativeSelectors from '@/components/comparative/ComparativeSelectors.vue';
import ComparativeTable from '@/components/comparative/ComparativeTable.vue';
import ChartCard from '@/components/radiography/ChartCard.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    columnOptions,
    percentColumnOptions,
    donutOptions,
    radialBarOptions,
    chartColors,
} from '@/lib/chart-theme';
import {
    HEADLINE_METRICS,
    CHART_CURRENCY_METRICS,
    CHART_PERCENT_METRICS,
    toneFor,
} from '@/lib/comparative-metrics';

defineOptions({ layout: AppLayout });

type PeriodOption = {
    id: number;
    label: string;
    code: string;
    type: string;
    has_snapshot: boolean;
};
type BranchOption = { id: number; name: string };
/** Renglón del comparativo: métrica, valor B (prev), valor A (curr), diferencia, variación % y formato. */
type Row = {
    label: string;
    prev: number;
    curr: number;
    diff: number;
    var_pct: number;
    fmt: 'currency' | 'percent' | 'integer';
};

/**
 * Props de MonthlyReportController::comparativoPage().
 * - initialPeriodAId / initialPeriodBId: periodos preseleccionados (A = actual, B = contra el que se compara).
 * - initialScope / initialBranchId / initialEmployeeId: alcance inicial (deep-link o forzado a sucursal si el usuario es restringido).
 * - periods: periodos para los selectores (has_snapshot indica si tienen radiografía).
 * - branches: sucursales operativas permitidas al usuario.
 * - comparativeDataUrl / employeesLookupUrl: endpoints JSON (ver comentario de cabecera).
 */
const props = defineProps<{
    initialPeriodAId: number | null;
    initialPeriodBId: number | null;
    initialScope: 'general' | 'branch' | 'employee';
    initialBranchId: number | null;
    initialEmployeeId: number | null;
    periods: PeriodOption[];
    branches: BranchOption[];
    comparativeDataUrl: string;
    employeesLookupUrl: string;
}>();

/** Selección actual de periodos (A = actual, B = anterior) ligada a ComparativeSelectors. */
const periodA = ref<number | null>(props.initialPeriodAId);
const periodB = ref<number | null>(props.initialPeriodBId);
// Fase 6.4 — sin alcance general no se ofrece el comparativo general (el backend da 403).
/** true salvo que `auth.dataScope.general` sea explícitamente false. */
const isGeneralUser = computed(
    () =>
        (
            usePage().props.auth as
                | { dataScope?: { general?: boolean } | null }
                | undefined
        )?.dataScope?.general !== false,
);
/** Alcance seleccionado; un usuario sin alcance general nunca arranca en 'general'. */
const scope = ref<'general' | 'branch' | 'employee'>(
    props.initialScope === 'general' && !isGeneralUser.value
        ? 'branch'
        : props.initialScope,
);
const branchId = ref<number | null>(props.initialBranchId);
const employeeId = ref<number | null>(props.initialEmployeeId);

// ════════════════════════════════════════════════════════════════════════════
// Colaboradores del alcance "Por gestor" — dependen del roster de Periodo A, se
// vuelven a pedir cada vez que cambia (B5). AbortController propio (C3): un cambio
// rápido de periodo nunca deja que una respuesta vieja pise a la más nueva.
// ════════════════════════════════════════════════════════════════════════════
const employees = ref<{ id: number; name: string }[]>([]);
const employeesLoading = ref(false);
/** Error al cargar la lista de gestores (se muestra debajo de los selectores). */
const employeesError = ref<string | null>(null);
let employeesController: AbortController | null = null;
let employeesVersion = 0;

/**
 * Carga el roster de gestores del Periodo A (GET employeesLookupUrl?period_id=...) para el
 * selector "Por gestor". Aborta la petición previa e ignora respuestas tardías.
 * Si falla (red o respuesta no exitosa) la lista queda como estaba y se muestra un aviso
 * en `employeesError`; un abort no se reporta.
 */
async function fetchEmployees() {
    // Se aborta e invalida la petición previa ANTES de cualquier salida temprana, para
    // que una respuesta vieja nunca llegue a pintar una lista que ya no corresponde.
    employeesController?.abort();
    const myVersion = ++employeesVersion;
    employeesError.value = null;

    if (!periodA.value) {
        employees.value = [];
        employeesLoading.value = false;

        return;
    }

    const controller = new AbortController();
    employeesController = controller;
    employeesLoading.value = true;

    try {
        const resp = await fetch(
            `${props.employeesLookupUrl}?period_id=${periodA.value}`,
            {
                signal: controller.signal,
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        const json: any = await resp.json().catch(() => ({}));

        if (myVersion !== employeesVersion) {
            return;
        }

        if (!resp.ok) {
            employeesError.value =
                json.error ??
                json.message ??
                `No se pudo cargar la lista de gestores (error ${resp.status}).`;

            return;
        }

        employees.value = json.employees ?? [];
    } catch (e: any) {
        if (e?.name === 'AbortError' || myVersion !== employeesVersion) {
            return;
        }

        employeesError.value =
            'No se pudo cargar la lista de gestores. Revisa tu conexión e inténtalo de nuevo.';
    } finally {
        if (myVersion === employeesVersion) {
            employeesLoading.value = false;
        }
    }
}

// ════════════════════════════════════════════════════════════════════════════
// Dataset comparativo — ÚNICA fuente de cifras: comparativoData() en el backend,
// que a su vez llama RadiografiaExportService::comparativeViewData() (la MISMA
// función que ya alimenta el Excel/PDF comparativo — B3). AbortController + token
// de versión (C3): nunca una respuesta vieja pisa a una más nueva. Mantiene el
// dataset anterior visible mientras llega el nuevo (C7) — solo un indicador chico
// "Actualizando…", nunca borra el dashboard completo.
// ════════════════════════════════════════════════════════════════════════════
const data = ref<any | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
let dataController: AbortController | null = null;
let dataVersion = 0;

/** Refleja periodos y alcance en la URL (history.replaceState) para que F5/compartir reabra el mismo comparativo. */
function updateQueryString() {
    const params = new URLSearchParams();

    if (periodA.value) {
        params.set('period_a', String(periodA.value));
    }

    if (periodB.value) {
        params.set('period_b', String(periodB.value));
    }

    if (scope.value !== 'general') {
        params.set('scope', scope.value);
    }

    if (scope.value === 'branch' && branchId.value) {
        params.set('branch_id', String(branchId.value));
    }

    if (scope.value === 'employee' && employeeId.value) {
        params.set('employee_id', String(employeeId.value));
    }

    const url = new URL(window.location.href);
    // Sin parámetros se deja la URL limpia (sin "?" colgando al final).
    url.search = params.toString();
    window.history.replaceState({}, '', url);
}

/**
 * Pide el dataset comparativo (GET comparativeDataUrl con period_a, period_b, scope y
 * branch_id/employee_id). Valida antes que haya dos periodos y, según alcance, sucursal o
 * colaborador; conserva el dataset anterior visible mientras carga y actualiza la URL al terminar.
 * La petición anterior se aborta antes de validar: si la selección nueva es inválida, una
 * respuesta vieja ya no puede llegar a pintar cifras de la selección previa.
 */
async function fetchComparativeData() {
    dataController?.abort();
    dataController = null;
    const myVersion = ++dataVersion;
    loading.value = false;

    if (!periodA.value || !periodB.value) {
        error.value = 'Selecciona los dos periodos a comparar.';

        return;
    }

    if (scope.value === 'branch' && !branchId.value) {
        error.value = 'Selecciona una sucursal.';

        return;
    }

    if (scope.value === 'employee' && !employeeId.value) {
        error.value = 'Selecciona un colaborador.';

        return;
    }

    const controller = new AbortController();
    dataController = controller;

    const params = new URLSearchParams({
        period_a: String(periodA.value),
        period_b: String(periodB.value),
        scope: scope.value,
    });

    if (scope.value === 'branch' && branchId.value) {
        params.set('branch_id', String(branchId.value));
    }

    if (scope.value === 'employee' && employeeId.value) {
        params.set('employee_id', String(employeeId.value));
    }

    loading.value = true;
    error.value = null;

    try {
        const resp = await fetch(`${props.comparativeDataUrl}?${params}`, {
            signal: controller.signal,
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const json: any = await resp.json().catch(() => ({}));

        if (myVersion !== dataVersion) {
            return;
        }

        if (!resp.ok) {
            error.value =
                json.error ??
                json.message ??
                `Error ${resp.status} al construir el comparativo.`;

            return;
        }

        data.value = json;
        updateQueryString();
    } catch (e: any) {
        if (e?.name === 'AbortError') {
            return;
        }

        if (myVersion !== dataVersion) {
            return;
        }

        error.value = e?.message ?? 'Error de red al construir el comparativo.';
    } finally {
        if (myVersion === dataVersion) {
            loading.value = false;
        }
    }
}

/** Botón "intercambiar" de ComparativeSelectors: invierte Periodo A y Periodo B. */
function swapPeriods() {
    const a = periodA.value;
    periodA.value = periodB.value;
    periodB.value = a;
}

/** Watch: al cambiar Periodo A se limpia el gestor elegido (el roster cambia) y se recarga la lista de gestores. */
watch(periodA, () => {
    if (scope.value === 'employee') {
        employeeId.value = null;
    }

    fetchEmployees();
});

/** Watch: cualquier cambio de periodos o alcance vuelve a pedir el comparativo. */
watch([periodA, periodB, scope, branchId, employeeId], () => {
    fetchComparativeData();
});

/** Carga inicial de gestores y del comparativo; al desmontar se abortan las peticiones en curso. */
onMounted(() => {
    fetchEmployees();
    fetchComparativeData();
});
onUnmounted(() => {
    dataController?.abort();
    employeesController?.abort();
});

// ── Derivados para el template ────────────────────────────────────────────────
/** Renglones del comparativo devueltos por el backend. */
const rows = computed<Row[]>(() => data.value?.rows ?? []);
/** Etiquetas de los periodos: las del dataset o, mientras carga, las del selector. */
const labelA = computed(
    () =>
        data.value?.periodA?.label ??
        props.periods.find((p) => p.id === periodA.value)?.label ??
        'Periodo A',
);
const labelB = computed(
    () =>
        data.value?.periodB?.label ??
        props.periods.find((p) => p.id === periodB.value)?.label ??
        'Periodo B',
);
// Mientras llega el dataset, nunca se rotula «General» a quien no tiene ese alcance.
const scopeLabel = computed(
    () =>
        data.value?.scopeLabel ??
        (
            {
                general: 'General',
                branch: 'Sucursal',
                employee: 'Gestor',
            } as const
        )[scope.value],
);

/** Renglones para las tarjetas KPI principales, en el orden de HEADLINE_METRICS. */
const headlineRows = computed(() =>
    HEADLINE_METRICS.map((label) =>
        rows.value.find((r) => r.label === label),
    ).filter((r): r is Row => !!r),
);

/** Exportación habilitada si el backend mandó exportUrls y no hay carga en curso; URLs de Excel/PDF. */
const canExport = computed(() => !!data.value?.exportUrls && !loading.value);
const excelUrl = computed(() => data.value?.exportUrls?.excel ?? null);
const pdfUrl = computed(() => data.value?.exportUrls?.pdf ?? null);

// "Mayores variaciones" (B12) — orden determinista por magnitud relativa, SIN IA.
const topVariations = computed(() => {
    return [...rows.value]
        .filter((r) => r.var_pct !== 0)
        .sort((a, b) => Math.abs(b.var_pct) - Math.abs(a.var_pct))
        .slice(0, 6);
});

/** Gráfica de barras de métricas en pesos (CHART_CURRENCY_METRICS): B en gris, A en verde azulado. */
const currencyChartRows = computed(() =>
    CHART_CURRENCY_METRICS.map((label) =>
        rows.value.find((r) => r.label === label),
    ).filter((r): r is Row => !!r),
);
const currencyChartSeries = computed(() => [
    { name: labelB.value, data: currencyChartRows.value.map((r) => r.prev) },
    { name: labelA.value, data: currencyChartRows.value.map((r) => r.curr) },
]);
const currencyChartOptions = computed(() =>
    columnOptions(
        currencyChartRows.value.map((r) => r.label),
        [chartColors.gray, chartColors.teal],
    ),
);

/** Gráfica de barras de métricas porcentuales (CHART_PERCENT_METRICS). */
const percentChartRows = computed(() =>
    CHART_PERCENT_METRICS.map((label) =>
        rows.value.find((r) => r.label === label),
    ).filter((r): r is Row => !!r),
);
const percentChartSeries = computed(() => [
    { name: labelB.value, data: percentChartRows.value.map((r) => r.prev) },
    { name: labelA.value, data: percentChartRows.value.map((r) => r.curr) },
]);
const percentChartOptions = computed(() =>
    percentColumnOptions(
        percentChartRows.value.map((r) => r.label),
        [chartColors.gray, chartColors.blue],
    ),
);

// ── Donut: composición Cartera sana vs vencida, un anillo por periodo ────────
// "Valor cartera" - "Cartera vencida" = sana (misma resta que ya usa el PDF/Excel
// comparativo — nunca una fórmula nueva). Dos donuts (A y B) en vez de uno solo
// mezclado, para poder comparar la proporción de un vistazo sin leer números.
/**
 * Serie [sana, vencida] de un periodo, redondeada a centavos.
 * @param valorRow Renglón "Valor cartera".
 * @param vencidaRow Renglón "Cartera vencida".
 * @param key 'curr' = Periodo A, 'prev' = Periodo B.
 */
function carteraComposicion(
    valorRow: Row | undefined,
    vencidaRow: Row | undefined,
    key: 'curr' | 'prev',
) {
    const cartera = valorRow ? valorRow[key] : 0;
    const vencida = vencidaRow ? vencidaRow[key] : 0;
    const sana = Math.max(0, cartera - vencida);

    return [Math.round(sana * 100) / 100, Math.round(vencida * 100) / 100];
}
const carteraValorRow = computed(() =>
    rows.value.find((r) => r.label === 'Valor cartera'),
);
const carteraVencidaRow = computed(() =>
    rows.value.find((r) => r.label === 'Cartera vencida'),
);
const carteraDonutOptions = computed(() =>
    donutOptions(['Sana', 'Vencida'], [chartColors.teal, chartColors.red]),
);
const carteraDonutSeriesA = computed(() =>
    carteraComposicion(carteraValorRow.value, carteraVencidaRow.value, 'curr'),
);
const carteraDonutSeriesB = computed(() =>
    carteraComposicion(carteraValorRow.value, carteraVencidaRow.value, 'prev'),
);

// ── Radial gauge: Mora % de A vs B en el mismo anillo ────────────────────────
/** Renglón "Mora %" y su gráfica radial (A en rojo, B en gris). */
const moraRow = computed(() => rows.value.find((r) => r.label === 'Mora %'));
const moraRadialOptions = computed(() =>
    radialBarOptions(
        [labelA.value, labelB.value],
        [chartColors.red, chartColors.gray],
    ),
);
const moraRadialSeries = computed(() => [
    moraRow.value?.curr ?? 0,
    moraRow.value?.prev ?? 0,
]);
</script>

<template>
    <div class="w-full space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- Encabezado: periodos comparados, alcance y botones de exportación -->
        <ComparativeHero
            :label-a="labelA"
            :label-b="labelB"
            :scope-label="scopeLabel"
            :excel-url="excelUrl"
            :pdf-url="pdfUrl"
            :can-export="canExport"
            back-url="/reportes-mensuales"
        />

        <!-- Selectores de Periodo A/B, alcance, sucursal y gestor -->
        <ComparativeSelectors
            v-model:period-a="periodA"
            v-model:period-b="periodB"
            v-model:scope="scope"
            v-model:branch-id="branchId"
            v-model:employee-id="employeeId"
            :periods="periods"
            :branches="branches"
            :employees="employees"
            :employees-loading="employeesLoading"
            :allow-general="isGeneralUser"
            @swap="swapPeriods"
        />

        <!-- Aviso de validación o error de carga -->
        <div v-if="error" class="app-banner app-banner-warning" role="alert">
            <AlertTriangle aria-hidden="true" />
            <span class="font-medium">{{ error }}</span>
        </div>
        <div
            v-if="employeesError && scope === 'employee'"
            class="app-banner app-banner-warning"
            role="alert"
        >
            <AlertTriangle aria-hidden="true" />
            <span class="font-medium">{{ employeesError }}</span>
        </div>

        <!-- Primera carga: panel de espera; después, el dashboard (se conserva visible mientras se actualiza) -->
        <ComparativeLoadingPanel
            v-if="loading && !data"
            :label-a="labelA"
            :label-b="labelB"
        />

        <template v-else-if="data">
            <p
                v-if="loading"
                class="inline-flex items-center gap-2 rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-foreground"
                role="status"
            >
                <Loader2 class="size-3.5 animate-spin" aria-hidden="true" />
                Actualizando comparativo… la primera consulta de un periodo
                puede tardar unos segundos.
            </p>

            <!-- Tarjetas KPI principales -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <ComparativeKpiCard
                    v-for="row in headlineRows"
                    :key="row.label"
                    :label="row.label"
                    :prev="row.prev"
                    :curr="row.curr"
                    :diff="row.diff"
                    :var-pct="row.var_pct"
                    :fmt="row.fmt"
                    :label-a="labelA"
                    :label-b="labelB"
                />
            </div>

            <!-- Gráficas de barras: montos y porcentajes -->
            <div class="grid gap-4 xl:grid-cols-2">
                <ChartCard
                    title="Comparación financiera principal"
                    subtitle="Recuperación, Colocación, EBITDA y OPEX"
                    type="bar"
                    :height="320"
                    :series="currencyChartSeries"
                    :options="currencyChartOptions"
                />
                <ChartCard
                    title="Indicadores porcentuales"
                    subtitle="Margen EBITDA, Mora y Rotación"
                    type="bar"
                    :height="320"
                    :series="percentChartSeries"
                    :options="percentChartOptions"
                />
            </div>

            <!-- Composición de cartera (una dona por periodo) y Mora % radial -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <ChartCard
                    :title="`Cartera sana vs vencida — ${labelA}`"
                    subtitle="Composición de la cartera vigente"
                    type="donut"
                    :height="260"
                    :series="carteraDonutSeriesA"
                    :options="carteraDonutOptions"
                />
                <ChartCard
                    :title="`Cartera sana vs vencida — ${labelB}`"
                    subtitle="Composición de la cartera vigente"
                    type="donut"
                    :height="260"
                    :series="carteraDonutSeriesB"
                    :options="carteraDonutOptions"
                />
                <ChartCard
                    title="Mora %"
                    subtitle="Comparativo en el mismo anillo"
                    type="radialBar"
                    :height="260"
                    :series="moraRadialSeries"
                    :options="moraRadialOptions"
                    class="sm:col-span-2 xl:col-span-2"
                />
            </div>

            <!-- Top 6 métricas con mayor variación % (orden determinista) -->
            <div class="app-section p-5">
                <h3 class="app-section-title">Mayores variaciones</h3>
                <p class="app-help mt-0.5">
                    Ordenadas por magnitud relativa — sin interpretación
                    automática, solo orden determinista.
                </p>
                <ul class="mt-3 divide-y divide-border">
                    <li
                        v-for="row in topVariations"
                        :key="row.label"
                        class="flex items-center justify-between gap-3 py-2.5 text-sm"
                    >
                        <span class="font-semibold text-foreground/80">{{
                            row.label
                        }}</span>
                        <span
                            class="font-bold"
                            :class="{
                                'text-emerald-700 dark:text-emerald-400':
                                    toneFor(row.label, row.var_pct) === 'good',
                                'text-rose-700 dark:text-rose-400':
                                    toneFor(row.label, row.var_pct) === 'bad',
                                'text-muted-foreground':
                                    toneFor(row.label, row.var_pct) ===
                                    'neutral',
                            }"
                        >
                            {{ row.var_pct > 0 ? '↑' : '↓' }}
                            {{ row.var_pct >= 0 ? '+' : ''
                            }}{{ row.var_pct.toFixed(2) }}%
                        </span>
                    </li>
                    <li
                        v-if="topVariations.length === 0"
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        Sin variaciones para este alcance.
                    </li>
                </ul>
            </div>

            <!-- Tabla completa de todas las métricas -->
            <ComparativeTable
                :rows="rows"
                :label-a="labelA"
                :label-b="labelB"
            />
        </template>
    </div>
</template>
