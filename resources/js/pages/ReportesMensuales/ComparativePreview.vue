<script setup lang="ts">
import { usePage } from '@inertiajs/vue3'
import { AlertTriangle, Loader2 } from 'lucide-vue-next'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import ComparativeHero from '@/components/comparative/ComparativeHero.vue'
import ComparativeKpiCard from '@/components/comparative/ComparativeKpiCard.vue'
import ComparativeLoadingPanel from '@/components/comparative/ComparativeLoadingPanel.vue'
import ComparativeSelectors from '@/components/comparative/ComparativeSelectors.vue'
import ComparativeTable from '@/components/comparative/ComparativeTable.vue'
import ChartCard from '@/components/radiography/ChartCard.vue'
import AppLayout from '@/layouts/AppLayout.vue'
import { columnOptions, percentColumnOptions, donutOptions, radialBarOptions, chartColors } from '@/lib/chart-theme'
import { HEADLINE_METRICS, CHART_CURRENCY_METRICS, CHART_PERCENT_METRICS, toneFor } from '@/lib/comparative-metrics'

defineOptions({ layout: AppLayout })

type PeriodOption = { id: number; label: string; code: string; type: string; has_snapshot: boolean }
type BranchOption = { id: number; name: string }
type Row = { label: string; prev: number; curr: number; diff: number; var_pct: number; fmt: 'currency' | 'percent' | 'integer' }

const props = defineProps<{
    initialPeriodAId: number | null
    initialPeriodBId: number | null
    initialScope: 'general' | 'branch' | 'employee'
    initialBranchId: number | null
    initialEmployeeId: number | null
    periods: PeriodOption[]
    branches: BranchOption[]
    comparativeDataUrl: string
    employeesLookupUrl: string
}>()

const periodA = ref<number | null>(props.initialPeriodAId)
const periodB = ref<number | null>(props.initialPeriodBId)
// Fase 6.4 — sin alcance general no se ofrece el comparativo general (el backend da 403).
const isGeneralUser = computed(() => (usePage().props.auth as { dataScope?: { general?: boolean } | null } | undefined)?.dataScope?.general !== false)
const scope = ref<'general' | 'branch' | 'employee'>(props.initialScope === 'general' && !isGeneralUser.value ? 'branch' : props.initialScope)
const branchId = ref<number | null>(props.initialBranchId)
const employeeId = ref<number | null>(props.initialEmployeeId)

// ════════════════════════════════════════════════════════════════════════════
// Colaboradores del alcance "Por gestor" — dependen del roster de Periodo A, se
// vuelven a pedir cada vez que cambia (B5). AbortController propio (C3): un cambio
// rápido de periodo nunca deja que una respuesta vieja pise a la más nueva.
// ════════════════════════════════════════════════════════════════════════════
const employees = ref<{ id: number; name: string }[]>([])
const employeesLoading = ref(false)
let employeesController: AbortController | null = null
let employeesVersion = 0

async function fetchEmployees() {
    if (!periodA.value) {
 employees.value = [];

 return 
}

    employeesController?.abort()
    const controller = new AbortController()
    employeesController = controller
    const myVersion = ++employeesVersion
    employeesLoading.value = true

    try {
        const resp = await fetch(`${props.employeesLookupUrl}?period_id=${periodA.value}`, {
            signal: controller.signal, cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        const json = await resp.json()

        if (myVersion !== employeesVersion) {
return
}

        employees.value = json.employees ?? []
    } catch (e: any) {
        if (e?.name === 'AbortError') {
return
}
    } finally {
        if (myVersion === employeesVersion) {
employeesLoading.value = false
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
const data = ref<any | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)
let dataController: AbortController | null = null
let dataVersion = 0

function updateQueryString() {
    const params = new URLSearchParams()

    if (periodA.value) {
params.set('period_a', String(periodA.value))
}

    if (periodB.value) {
params.set('period_b', String(periodB.value))
}

    if (scope.value !== 'general') {
params.set('scope', scope.value)
}

    if (scope.value === 'branch' && branchId.value) {
params.set('branch_id', String(branchId.value))
}

    if (scope.value === 'employee' && employeeId.value) {
params.set('employee_id', String(employeeId.value))
}

    const url = new URL(window.location.href)
    url.search = `?${params.toString()}`
    window.history.replaceState({}, '', url)
}

async function fetchComparativeData() {
    if (!periodA.value || !periodB.value) {
 error.value = 'Selecciona los dos periodos a comparar.';

 return 
}

    if (scope.value === 'branch' && !branchId.value) {
 error.value = 'Selecciona una sucursal.';

 return 
}

    if (scope.value === 'employee' && !employeeId.value) {
 error.value = 'Selecciona un colaborador.';

 return 
}

    dataController?.abort()
    const controller = new AbortController()
    dataController = controller
    const myVersion = ++dataVersion

    const params = new URLSearchParams({ period_a: String(periodA.value), period_b: String(periodB.value), scope: scope.value })

    if (scope.value === 'branch' && branchId.value) {
params.set('branch_id', String(branchId.value))
}

    if (scope.value === 'employee' && employeeId.value) {
params.set('employee_id', String(employeeId.value))
}

    loading.value = true
    error.value = null

    try {
        const resp = await fetch(`${props.comparativeDataUrl}?${params}`, {
            signal: controller.signal, cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        const json = await resp.json()

        if (myVersion !== dataVersion) {
return
}

        if (!resp.ok) {
 error.value = json.error ?? json.message ?? `Error ${resp.status} al construir el comparativo.`;

 return 
}

        data.value = json
        updateQueryString()
    } catch (e: any) {
        if (e?.name === 'AbortError') {
return
}

        if (myVersion !== dataVersion) {
return
}

        error.value = e?.message ?? 'Error de red al construir el comparativo.'
    } finally {
        if (myVersion === dataVersion) {
loading.value = false
}
    }
}

function swapPeriods() {
    const a = periodA.value
    periodA.value = periodB.value
    periodB.value = a
}

watch(periodA, () => {
    if (scope.value === 'employee') {
employeeId.value = null
}

    fetchEmployees()
})

watch([periodA, periodB, scope, branchId, employeeId], () => {
    fetchComparativeData()
})

onMounted(() => {
    fetchEmployees()
    fetchComparativeData()
})
onUnmounted(() => {
    dataController?.abort()
    employeesController?.abort()
})

// ── Derivados para el template ────────────────────────────────────────────────
const rows = computed<Row[]>(() => data.value?.rows ?? [])
const labelA = computed(() => data.value?.periodA?.label ?? props.periods.find(p => p.id === periodA.value)?.label ?? 'Periodo A')
const labelB = computed(() => data.value?.periodB?.label ?? props.periods.find(p => p.id === periodB.value)?.label ?? 'Periodo B')
// Mientras llega el dataset, nunca se rotula «General» a quien no tiene ese alcance.
const scopeLabel = computed(() => data.value?.scopeLabel ?? ({ general: 'General', branch: 'Sucursal', employee: 'Gestor' } as const)[scope.value])

const headlineRows = computed(() => HEADLINE_METRICS.map(label => rows.value.find(r => r.label === label)).filter((r): r is Row => !!r))

const canExport = computed(() => !!data.value?.exportUrls && !loading.value)
const excelUrl = computed(() => data.value?.exportUrls?.excel ?? null)
const pdfUrl = computed(() => data.value?.exportUrls?.pdf ?? null)

// "Mayores variaciones" (B12) — orden determinista por magnitud relativa, SIN IA.
const topVariations = computed(() => {
    return [...rows.value]
        .filter(r => r.var_pct !== 0)
        .sort((a, b) => Math.abs(b.var_pct) - Math.abs(a.var_pct))
        .slice(0, 6)
})

const currencyChartRows = computed(() => CHART_CURRENCY_METRICS.map(label => rows.value.find(r => r.label === label)).filter((r): r is Row => !!r))
const currencyChartSeries = computed(() => [
    { name: labelB.value, data: currencyChartRows.value.map(r => r.prev) },
    { name: labelA.value, data: currencyChartRows.value.map(r => r.curr) },
])
const currencyChartOptions = computed(() => columnOptions(currencyChartRows.value.map(r => r.label), [chartColors.gray, chartColors.teal]))

const percentChartRows = computed(() => CHART_PERCENT_METRICS.map(label => rows.value.find(r => r.label === label)).filter((r): r is Row => !!r))
const percentChartSeries = computed(() => [
    { name: labelB.value, data: percentChartRows.value.map(r => r.prev) },
    { name: labelA.value, data: percentChartRows.value.map(r => r.curr) },
])
const percentChartOptions = computed(() => percentColumnOptions(percentChartRows.value.map(r => r.label), [chartColors.gray, chartColors.blue]))

// ── Donut: composición Cartera sana vs vencida, un anillo por periodo ────────
// "Valor cartera" - "Cartera vencida" = sana (misma resta que ya usa el PDF/Excel
// comparativo — nunca una fórmula nueva). Dos donuts (A y B) en vez de uno solo
// mezclado, para poder comparar la proporción de un vistazo sin leer números.
function carteraComposicion(valorRow: Row | undefined, vencidaRow: Row | undefined, key: 'curr' | 'prev') {
    const cartera = valorRow ? valorRow[key] : 0
    const vencida = vencidaRow ? vencidaRow[key] : 0
    const sana = Math.max(0, cartera - vencida)

    return [Math.round(sana * 100) / 100, Math.round(vencida * 100) / 100]
}
const carteraValorRow = computed(() => rows.value.find(r => r.label === 'Valor cartera'))
const carteraVencidaRow = computed(() => rows.value.find(r => r.label === 'Cartera vencida'))
const carteraDonutOptions = computed(() => donutOptions(['Sana', 'Vencida'], [chartColors.teal, chartColors.red]))
const carteraDonutSeriesA = computed(() => carteraComposicion(carteraValorRow.value, carteraVencidaRow.value, 'curr'))
const carteraDonutSeriesB = computed(() => carteraComposicion(carteraValorRow.value, carteraVencidaRow.value, 'prev'))

// ── Radial gauge: Mora % de A vs B en el mismo anillo ────────────────────────
const moraRow = computed(() => rows.value.find(r => r.label === 'Mora %'))
const moraRadialOptions = computed(() => radialBarOptions([labelA.value, labelB.value], [chartColors.red, chartColors.gray]))
const moraRadialSeries = computed(() => [moraRow.value?.curr ?? 0, moraRow.value?.prev ?? 0])
</script>

<template>
    <div class="w-full space-y-6 p-4 sm:p-6 lg:p-8">
        <ComparativeHero
            :label-a="labelA" :label-b="labelB" :scope-label="scopeLabel"
            :excel-url="excelUrl" :pdf-url="pdfUrl" :can-export="canExport"
            back-url="/reportes-mensuales"
        />

        <ComparativeSelectors
            v-model:period-a="periodA" v-model:period-b="periodB" v-model:scope="scope"
            v-model:branch-id="branchId" v-model:employee-id="employeeId"
            :periods="periods" :branches="branches" :employees="employees" :employees-loading="employeesLoading"
            :allow-general="isGeneralUser" @swap="swapPeriods"
        />

        <div v-if="error" class="app-banner app-banner-warning" role="alert">
            <AlertTriangle aria-hidden="true" />
            <span class="font-medium">{{ error }}</span>
        </div>

        <ComparativeLoadingPanel v-if="loading && !data" :label-a="labelA" :label-b="labelB" />

        <template v-else-if="data">
            <p v-if="loading" class="inline-flex items-center gap-2 rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-foreground" role="status"><Loader2 class="size-3.5 animate-spin" aria-hidden="true" /> Actualizando comparativo… la primera consulta de un periodo puede tardar unos segundos.</p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <ComparativeKpiCard v-for="row in headlineRows" :key="row.label"
                    :label="row.label" :prev="row.prev" :curr="row.curr" :diff="row.diff" :var-pct="row.var_pct" :fmt="row.fmt"
                    :label-a="labelA" :label-b="labelB" />
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <ChartCard title="Comparación financiera principal" subtitle="Recuperación, Colocación, EBITDA y OPEX" type="bar" :height="320" :series="currencyChartSeries" :options="currencyChartOptions" />
                <ChartCard title="Indicadores porcentuales" subtitle="Margen EBITDA, Mora y Rotación" type="bar" :height="320" :series="percentChartSeries" :options="percentChartOptions" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <ChartCard :title="`Cartera sana vs vencida — ${labelA}`" subtitle="Composición de la cartera vigente" type="donut" :height="260" :series="carteraDonutSeriesA" :options="carteraDonutOptions" />
                <ChartCard :title="`Cartera sana vs vencida — ${labelB}`" subtitle="Composición de la cartera vigente" type="donut" :height="260" :series="carteraDonutSeriesB" :options="carteraDonutOptions" />
                <ChartCard title="Mora %" subtitle="Comparativo en el mismo anillo" type="radialBar" :height="260" :series="moraRadialSeries" :options="moraRadialOptions" class="sm:col-span-2 xl:col-span-2" />
            </div>

            <div class="app-section p-5">
                <h3 class="app-section-title">Mayores variaciones</h3>
                <p class="app-help mt-0.5">Ordenadas por magnitud relativa — sin interpretación automática, solo orden determinista.</p>
                <ul class="mt-3 divide-y divide-border">
                    <li v-for="row in topVariations" :key="row.label" class="flex items-center justify-between gap-3 py-2.5 text-sm">
                        <span class="font-semibold text-foreground/80">{{ row.label }}</span>
                        <span class="font-bold" :class="{
                            'text-emerald-700 dark:text-emerald-400': toneFor(row.label, row.var_pct) === 'good',
                            'text-rose-700 dark:text-rose-400': toneFor(row.label, row.var_pct) === 'bad',
                            'text-muted-foreground ': toneFor(row.label, row.var_pct) === 'neutral',
                        }">
                            {{ row.var_pct > 0 ? '↑' : '↓' }} {{ row.var_pct >= 0 ? '+' : '' }}{{ row.var_pct.toFixed(2) }}%
                        </span>
                    </li>
                    <li v-if="topVariations.length === 0" class="py-4 text-center text-sm text-muted-foreground">Sin variaciones para este alcance.</li>
                </ul>
            </div>

            <ComparativeTable :rows="rows" :label-a="labelA" :label-b="labelB" />
        </template>
    </div>
</template>
