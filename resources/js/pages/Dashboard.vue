<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3'
import {
    ArrowDownRight,
    ArrowUpRight,
    Banknote,
    Building2,
    CalendarRange,
    FileSpreadsheet,
    FolderOpen,
    Landmark,
    LoaderCircle,
    PiggyBank,
    RefreshCw,
    Target,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import DashboardExportActions from '@/components/dashboard/DashboardExportActions.vue'
import DashboardKpiCard from '@/components/dashboard/DashboardKpiCard.vue'
import SelectField from '@/components/forms/SelectField.vue'
import ChartCard from '@/components/radiography/ChartCard.vue'
import { dashboard } from '@/routes'

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
})

type Kpis = {
    period_label: string
    period_id: number
    ebitda: number
    margen_ebitda: number
    opex: number
    colocacion: number
    recuperacion: number
    cartera: number
    mora_pct: number
    colaboradores: number
}

type ChartsData = {
    moraBuckets: { label: string; valor: number }[]
    categorias: { nombre: string; ebitda: number; categoria: string }[]
    categoriaLabels: string[]
    categoriaCounts: number[]
    categoriaColors: string[]
    sucursalRows: { sucursal: string; colocacion: number; recuperacion: number }[]
}

type TrendPoint = { label: string; period_id?: number; ebitda: number | null; opex: number | null; available?: boolean }

type ScopeInfo = { type: 'general' | 'branch'; branch_id: number | null; branch_name: string | null; available: boolean }

const props = defineProps<{
    hasData: boolean
    periods?: { id: number; label: string }[]
    branches?: { id: number; name: string }[]
    kpis?: Kpis | null
    charts?: ChartsData | null
    scope?: ScopeInfo
    scopeUnavailable?: boolean
    // Carga diferida: la página abre sin cifras y las pide a /dashboard-data al montarse.
    initialPeriodId?: number
    deferred?: boolean
}>()

// Alcance del usuario (Fase 6.4, otro módulo) y permisos de acción — solo UX: la
// autorización real vive en el backend (DataScopeService / permission:*).
const page = usePage()
const auth = computed(() => (page.props as { auth?: { permissions?: string[]; dataScope?: { general?: boolean } | null } }).auth)
const canExport = computed(() => {
    const perms = auth.value?.permissions

    return !Array.isArray(perms) || perms.includes('reportes.exportar')
})
const isGeneralUser = computed(() => auth.value?.dataScope?.general !== false)

const kpis = ref<Kpis | null | undefined>(props.kpis)
const charts = ref<ChartsData | null | undefined>(props.charts)
const scope = ref<ScopeInfo | undefined>(props.scope)
const scopeUnavailable = ref(props.scopeUnavailable ?? false)
const selectedPeriodId = ref<string | number>(props.kpis?.period_id ?? props.initialPeriodId ?? props.periods?.[0]?.id ?? '')
// '' = Todas las sucursales. Un usuario restringido llega con su sucursal ya resuelta por el backend.
const selectedBranchId = ref<string | number>(props.scope?.branch_id ?? '')

const loading = ref(!!props.deferred && props.hasData)
const loadError = ref<string | null>(null)

const trend = ref<TrendPoint[]>([])
const trendScopeLabel = ref<string>('')
const trendLoading = ref(true)
const trendError = ref(false)

const branchIdOrNull = () => (selectedBranchId.value ? Number(selectedBranchId.value) : null)
const scopeLabel = computed(() => {
    const id = branchIdOrNull()

    if (!id) {
        return 'Todas las sucursales'
    }

    return props.branches?.find((b) => b.id === id)?.name ?? scope.value?.branch_name ?? 'Sucursal'
})

// ── Datos del periodo/sucursal ─────────────────────────────────────────────────
// Fase 7.4/8.5: ya NO se bloquea la pantalla completa al cambiar un filtro — solo
// las tarjetas/gráficas muestran su estado de carga. Cambiar rápido entre periodos o
// sucursales nunca pinta una respuesta vieja: cada petición aborta la anterior y,
// además, su número de secuencia debe seguir siendo el vigente al llegar.
let dataController: AbortController | null = null
let dataSeq = 0

async function loadPeriod(periodId: number, branchId: number | null) {
    dataController?.abort()
    const ctrl = new AbortController()
    dataController = ctrl
    const seq = ++dataSeq
    loading.value = true
    loadError.value = null

    try {
        const params = new URLSearchParams({ period_id: String(periodId) })

        if (branchId) {
            params.set('branch_id', String(branchId))
        }

        const resp = await fetch(`/dashboard-data?${params.toString()}`, {
            signal: ctrl.signal,
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })

        if (!resp.ok) {
            const body = await resp.json().catch(() => null)

            throw new Error(body?.message || `El servidor respondió ${resp.status}.`)
        }

        const json = await resp.json()

        if (seq !== dataSeq) {
            return
        }

        if (json.hasData) {
            kpis.value = json.kpis ?? null
            charts.value = json.charts ?? null
            scope.value = json.scope
            scopeUnavailable.value = !!json.scopeUnavailable
        }
    } catch (e: unknown) {
        if ((e as { name?: string })?.name === 'AbortError' || seq !== dataSeq) {
            return
        }

        // fetch() sin respuesta (red caída/servidor inaccesible) lanza TypeError con un
        // texto del navegador en inglés: se reemplaza por un mensaje que diga qué hacer.
        loadError.value = e instanceof TypeError
            ? 'Sin conexión con el servidor. Revisa tu red e intenta de nuevo.'
            : (e as Error)?.message || 'No se pudo actualizar el Dashboard.'
    } finally {
        if (seq === dataSeq) {
            loading.value = false
        }
    }
}

// ── Tendencia (Fase 7.1: sigue el alcance elegido) ────────────────────────────
// Se pide aparte (nunca bloquea KPI/gráficas) y SOLO cuando cambia la sucursal: es
// la misma para cualquier periodo. Memo local por alcance para que volver a una
// sucursal ya vista sea instantáneo; la frescura la garantiza la caché del backend
// (por versión del resumen de cada periodo).
const trendMemo = new Map<string, { trend: TrendPoint[]; label: string }>()
let trendController: AbortController | null = null
let trendSeq = 0

async function loadTrend(force = false) {
    const branchId = branchIdOrNull()
    const key = branchId ? `b${branchId}` : 'general'
    const memo = trendMemo.get(key)

    trendController?.abort()

    if (memo && !force) {
        trend.value = memo.trend
        trendScopeLabel.value = memo.label
        trendLoading.value = false
        trendError.value = false

        return
    }

    const ctrl = new AbortController()
    trendController = ctrl
    const seq = ++trendSeq
    trendLoading.value = true
    trendError.value = false

    try {
        const params = new URLSearchParams()

        if (branchId) {
            params.set('branch_id', String(branchId))
        }

        const qs = params.toString()
        const resp = await fetch(`/dashboard-trend${qs ? `?${qs}` : ''}`, {
            signal: ctrl.signal,
            cache: 'no-store',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })

        if (!resp.ok) {
            throw new Error(`HTTP ${resp.status}`)
        }

        const json = await resp.json()

        if (seq !== trendSeq) {
            return
        }

        const entry = { trend: (json.trend ?? []) as TrendPoint[], label: json.scope?.label ?? scopeLabel.value }
        trendMemo.set(key, entry)
        trend.value = entry.trend
        trendScopeLabel.value = entry.label
    } catch (e: unknown) {
        if ((e as { name?: string })?.name === 'AbortError' || seq !== trendSeq) {
            return
        }

        trendError.value = true
    } finally {
        if (seq === trendSeq) {
            trendLoading.value = false
        }
    }
}

onMounted(() => {
    if (!props.hasData) {
        trendLoading.value = false

        return
    }

    if (props.deferred && selectedPeriodId.value) {
        loadPeriod(Number(selectedPeriodId.value), branchIdOrNull())
    }

    loadTrend()
})

onBeforeUnmount(() => {
    dataController?.abort()
    trendController?.abort()
})

function reload() {
    if (!selectedPeriodId.value) {
        return
    }

    loadPeriod(Number(selectedPeriodId.value), branchIdOrNull())
}

watch(selectedPeriodId, reload)
watch(selectedBranchId, () => {
    reload()
    loadTrend()
})

const periodOptions = computed(() => (props.periods ?? []).map((p) => ({ value: p.id, label: p.label })))
const branchOptions = computed(() => {
    const list = (props.branches ?? []).map((b) => ({ value: b.id, label: b.name }))

    // "Todas las sucursales" solo para alcance general (un restringido no puede ver el consolidado).
    return isGeneralUser.value ? [{ value: '', label: 'Todas las sucursales' }, ...list] : list
})
const selectedPeriodLabel = computed(() => props.periods?.find((p) => p.id === Number(selectedPeriodId.value))?.label ?? kpis.value?.period_label ?? '')

// Exportar (Fase 7.2/7.3): PDF y Excel del MISMO periodo y alcance que se ven aquí.
const exportUrls = computed(() => {
    const periodId = kpis.value?.period_id

    if (!periodId) {
        return null
    }

    const params = new URLSearchParams({ period_id: String(periodId) })
    const branchId = branchIdOrNull()

    if (branchId) {
        params.set('branch_id', String(branchId))
    }

    return { pdf: `/dashboard/exportar.pdf?${params}`, excel: `/dashboard/exportar.xlsx?${params}` }
})

// Signo antes del símbolo (-$21,249), igual que el PDF y Excel — nunca "$-21,249".
const money = (v: number) => (Number(v) < 0 ? '-' : '') + '$' + Math.abs(Number(v || 0)).toLocaleString('es-MX', { maximumFractionDigits: 0 })
const pct = (v: number) => `${Number(v || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`

const heroCards = computed(() => {
    const k = kpis.value

    if (!k) {
        return []
    }

    return [
        { label: 'EBITDA', value: money(k.ebitda), icon: TrendingUp, tone: k.ebitda < 0 ? 'danger' : 'success', hint: 'Ingreso base EBITDA − gastos totales' },
        { label: 'Margen EBITDA', value: pct(k.margen_ebitda), icon: Target, tone: k.margen_ebitda < 0 ? 'danger' : 'info', hint: 'EBITDA sobre ingreso base' },
    ] as const
})

const kpiCards = computed(() => {
    const k = kpis.value

    if (!k) {
        return []
    }

    return [
        { label: 'OPEX', value: money(k.opex), icon: Wallet, tone: 'danger' },
        { label: 'Colocación', value: money(k.colocacion), icon: Banknote, tone: 'info' },
        { label: 'Recuperación', value: money(k.recuperacion), icon: PiggyBank, tone: 'success' },
        { label: 'Cartera', value: money(k.cartera), icon: Landmark, tone: 'neutral' },
        { label: 'Mora', value: pct(k.mora_pct), icon: k.mora_pct > 15 ? ArrowUpRight : ArrowDownRight, tone: k.mora_pct > 15 ? 'danger' : 'success' },
        { label: 'Colaboradores', value: Number(k.colaboradores || 0).toLocaleString('es-MX'), icon: Users, tone: 'warning' },
    ] as const
})

const isBranchScope = computed(() => (charts.value?.sucursalRows?.length ?? 0) === 1 && !!branchIdOrNull())
const missingTrendPoints = computed(() => trend.value.filter((t) => t.available === false).map((t) => t.label))

const trendOptions = computed(() => ({
    chart: { toolbar: { show: false }, zoom: { enabled: false }, animations: { speed: 400 }, background: 'transparent' },
    colors: ['#106A59', '#EF4444'],
    stroke: { curve: 'smooth', width: 3 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
    dataLabels: { enabled: false },
    markers: { size: 4, strokeWidth: 2, hover: { size: 6 } },
    xaxis: { categories: trend.value.map((t) => t.label), labels: { style: { fontSize: '11px' } } },
    yaxis: { labels: { formatter: (v: number) => money(v) } },
    tooltip: { y: { formatter: (v: number | null) => (v === null || v === undefined ? 'Sin radiografía' : money(v)) } },
    legend: { position: 'top', horizontalAlign: 'right', fontSize: '12px' },
}))
// null (sucursal sin radiografía ese mes) se deja como hueco en la línea — nunca un 0 inventado.
const trendSeries = computed(() => [
    { name: 'EBITDA', data: trend.value.map((t) => t.ebitda) },
    { name: 'OPEX', data: trend.value.map((t) => t.opex) },
])

const categoriaOptions = computed(() => ({
    chart: { toolbar: { show: false }, background: 'transparent' },
    labels: charts.value?.categoriaLabels ?? [],
    colors: charts.value?.categoriaColors ?? [],
    legend: { position: 'bottom', fontSize: '12px' },
    dataLabels: { enabled: false },
    stroke: { width: 2, colors: ['#fff'] },
    plotOptions: { pie: { donut: { size: '68%' } } },
    tooltip: { y: { formatter: (v: number) => `${v} sucursal(es)` } },
}))
const categoriaSeries = computed(() => charts.value?.categoriaCounts ?? [])
const branchCategory = computed(() => (isBranchScope.value ? charts.value?.categorias?.[0] ?? null : null))

const moraOptions = computed(() => ({
    chart: { toolbar: { show: false }, background: 'transparent' },
    colors: ['#EF4444'],
    plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false },
    xaxis: { categories: (charts.value?.moraBuckets ?? []).map((b) => b.label), labels: { style: { fontSize: '11px' } } },
    yaxis: { labels: { formatter: (v: number) => money(v) } },
    tooltip: { y: { formatter: (v: number) => money(v) } },
}))
const moraSeries = computed(() => [{ name: 'Cartera vencida', data: (charts.value?.moraBuckets ?? []).map((b) => b.valor) }])

const sucursalOptions = computed(() => ({
    chart: { toolbar: { show: false }, background: 'transparent' },
    colors: ['#106A59', '#1DC1A2'],
    plotOptions: { bar: { borderRadius: 5, columnWidth: isBranchScope.value ? '30%' : '60%' } },
    dataLabels: { enabled: false },
    xaxis: { categories: (charts.value?.sucursalRows ?? []).map((r) => r.sucursal), labels: { style: { fontSize: '10px' }, rotate: -45 } },
    yaxis: { labels: { formatter: (v: number) => money(v) } },
    tooltip: { y: { formatter: (v: number) => money(v) } },
    legend: { position: 'top', horizontalAlign: 'right', fontSize: '12px' },
}))
const sucursalSeries = computed(() => [
    { name: 'Colocación', data: (charts.value?.sucursalRows ?? []).map((r) => r.colocacion) },
    { name: 'Recuperación', data: (charts.value?.sucursalRows ?? []).map((r) => r.recuperacion) },
])

const quickLinks = [
    { label: 'Carga de archivos', href: '/historico-general', icon: FolderOpen, color: 'bg-accent text-primary' },
    { label: 'Periodos', href: '/periodos', icon: CalendarRange, color: 'bg-accent text-primary' },
    { label: 'Colaboradores', href: '/asignaciones-empleado-sucursal', icon: Users, color: 'bg-accent text-primary' },
    { label: 'Reportes mensuales', href: '/reportes-mensuales', icon: FileSpreadsheet, color: 'bg-accent text-primary' },
]

// Clave de remontaje de gráficas: VueApexCharts no siempre reaplica funciones
// (formatter) al actualizar en sitio; remontar al cambiar periodo/alcance es lo fiable.
const chartKey = computed(() => `${kpis.value?.period_id ?? 'x'}-${scope.value?.branch_id ?? 'g'}`)
</script>

<template>
    <Head title="Dashboard" />
    <div class="space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- Encabezado + filtros + exportación -->
        <section class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-4 shadow-soft-xs sm:p-5 xl:flex-row xl:items-center xl:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex size-11 items-center justify-center rounded-xl border bg-accent text-primary">
                    <Building2 class="size-5" aria-hidden="true" />
                </div>
                <div>
                    <h1 class="text-xl font-bold tracking-tight text-foreground">Resumen ejecutivo</h1>
                    <p class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                        <span>{{ selectedPeriodLabel || 'Sin datos' }}</span>
                        <span v-if="hasData" aria-hidden="true">·</span>
                        <span v-if="hasData" class="font-semibold text-foreground/80">{{ scopeLabel }}</span>
                        <span v-if="loading" class="inline-flex items-center gap-1 font-semibold text-info" role="status">
                            <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" /> Actualizando…
                        </span>
                    </p>
                </div>
            </div>

            <div v-if="hasData" class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                <div v-if="periods?.length" data-tour="dashboard-period" class="w-full sm:w-52">
                    <SelectField v-model="selectedPeriodId" label="Periodo" :options="periodOptions" placeholder="Selecciona un periodo" />
                </div>
                <div v-if="branches?.length" data-tour="dashboard-branch" class="w-full sm:w-56">
                    <SelectField v-model="selectedBranchId" label="Sucursal" :options="branchOptions" placeholder="Todas las sucursales" />
                </div>
                <div v-if="exportUrls && canExport" data-tour="dashboard-export">
                    <DashboardExportActions :pdf-url="exportUrls.pdf" :excel-url="exportUrls.excel" :scope-label="`${selectedPeriodLabel} · ${scopeLabel}`" :disabled="loading" />
                </div>
            </div>
        </section>

        <div v-if="loadError" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-danger-border bg-danger-soft px-4 py-3 text-sm text-danger" role="alert">
            <span><strong>No se pudo actualizar el Dashboard.</strong> {{ loadError }}</span>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-xl bg-card px-3 py-1.5 text-xs font-bold shadow-soft-xs hover:bg-muted" @click="reload">
                <RefreshCw class="size-3.5" aria-hidden="true" /> Reintentar
            </button>
        </div>

        <div v-if="!hasData" class="rounded-2xl border border-dashed border-border bg-card p-10 text-center">
            <p class="text-sm font-semibold text-muted-foreground">Todavía no hay ninguna radiografía generada.</p>
            <Link href="/historico-general" class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-primary-foreground transition hover:bg-primary/90">
                Ir a Carga de archivos →
            </Link>
        </div>

        <!-- Sucursal sin radiografía en este periodo — nunca una pantalla vacía sin explicar por qué. -->
        <div v-else-if="scopeUnavailable && !loading" class="rounded-[1.75rem] border border-dashed border-warning-border bg-warning-soft p-10 text-center">
            <p class="text-sm font-semibold text-warning">
                Sin datos de radiografía para {{ scope?.branch_name ?? 'esta sucursal' }} en {{ selectedPeriodLabel || 'este periodo' }}.
            </p>
            <p class="mt-1 text-xs text-warning/80">Elige otra sucursal o revisa que la radiografía de este periodo ya haya sido generada.</p>
            <button v-if="isGeneralUser" type="button" class="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-card px-3 py-2 text-xs font-bold text-warning shadow-soft-xs transition hover:bg-muted" @click="selectedBranchId = ''">
                Ver todas las sucursales
            </button>
        </div>

        <template v-else>
            <!-- KPIs: EBITDA y margen destacados; el resto en tarjetas compactas -->
            <section aria-label="Indicadores clave" class="space-y-3">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <DashboardKpiCard v-for="card in heroCards" :key="card.label" v-bind="card" hero :loading="loading" />
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
                    <DashboardKpiCard v-for="card in kpiCards" :key="card.label" v-bind="card" :loading="loading" />
                </div>
            </section>

            <!-- Gráficas: todas siguen el alcance elegido (sucursal o consolidado) -->
            <section :class="['grid gap-4 transition-opacity duration-200 lg:grid-cols-2', loading && 'pointer-events-none opacity-60']" :aria-busy="loading">
                <div class="relative lg:col-span-2">
                    <ChartCard
                        :key="`trend-${trendScopeLabel}-${trend.length}`"
                        title="EBITDA vs OPEX"
                        :subtitle="`Tendencia mensual · ${trendScopeLabel || scopeLabel}${missingTrendPoints.length ? ` · sin radiografía en ${missingTrendPoints.join(', ')}` : ''}`"
                        type="area"
                        :series="trendSeries"
                        :options="trendOptions"
                        :loading="trendLoading"
                    />
                    <div v-if="trendError && !trendLoading" class="absolute top-4 right-5 flex items-center gap-2 text-[11px] font-semibold text-danger" role="alert">
                        No se pudo cargar la tendencia.
                        <button type="button" class="rounded-full bg-danger-soft px-2.5 py-1 transition duration-200 hover:opacity-80" @click="loadTrend(true)">
                            Reintentar
                        </button>
                    </div>
                </div>
                <template v-if="charts">
                    <div v-if="branchCategory" class="flex flex-col rounded-2xl border bg-card p-5 text-card-foreground shadow-sm">
                        <h3 class="text-xs font-black tracking-wider text-muted-foreground uppercase">Categoría EBITDA de la sucursal</h3>
                        <p class="mt-0.5 text-[11px] text-muted-foreground/80">{{ branchCategory.nombre }} · según su EBITDA del periodo</p>
                        <p class="mt-6 text-3xl font-black tracking-tight" :style="{ color: charts.categoriaColors?.[0] }">{{ branchCategory.categoria }}</p>
                        <p class="mt-1 text-sm font-semibold tabular-nums text-muted-foreground">EBITDA {{ money(branchCategory.ebitda) }}</p>
                    </div>
                    <ChartCard v-else :key="`categoria-${chartKey}`" title="Categoría EBITDA por sucursal" subtitle="Sucursales según su desempeño" type="donut" :series="categoriaSeries" :options="categoriaOptions" />
                    <ChartCard :key="`mora-${chartKey}`" title="Cartera vencida por antigüedad" :subtitle="`Mora por bucket · ${scopeLabel}`" type="bar" :series="moraSeries" :options="moraOptions" />
                    <ChartCard
                        :key="`sucursal-${chartKey}`"
                        title="Colocación vs. recuperación"
                        :subtitle="isBranchScope ? scopeLabel : 'Por sucursal'"
                        type="bar"
                        :series="sucursalSeries"
                        :options="sucursalOptions"
                        class="lg:col-span-2"
                        :height="320"
                    />
                </template>
                <div v-else-if="!loading" class="rounded-2xl border border-dashed border-border bg-card p-6 text-center text-xs text-muted-foreground lg:col-span-2">
                    Este alcance no tiene desglose por sucursal en la radiografía del periodo.
                </div>
            </section>
        </template>

        <!-- Accesos rápidos -->
        <nav aria-label="Accesos rápidos" class="flex flex-wrap gap-3">
            <Link
                v-for="link in quickLinks"
                :key="link.href"
                :href="link.href"
                class="group flex items-center gap-2.5 rounded-2xl border border-border bg-card px-4 py-2.5 shadow-soft-xs transition duration-200 hover:-translate-y-0.5 hover:shadow-soft-sm"
            >
                <div :class="['flex size-7 shrink-0 items-center justify-center rounded-lg', link.color]">
                    <component :is="link.icon" class="size-3.5" aria-hidden="true" />
                </div>
                <span class="text-xs font-bold text-foreground/80 transition-colors group-hover:text-foreground">{{ link.label }}</span>
            </Link>
        </nav>
    </div>
</template>
