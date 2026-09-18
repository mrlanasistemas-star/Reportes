<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import {
    ArrowDownRight,
    ArrowUpRight,
    Banknote,
    Building2,
    CalendarRange,
    FileSpreadsheet,
    FolderOpen,
    Landmark,
    PiggyBank,
    Target,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-vue-next'
import ChartCard from '@/components/radiography/ChartCard.vue'
import SelectField from '@/components/forms/SelectField.vue'
import { hideRouteLoading, showRouteLoading } from '@/lib/routeLoadingOverlay'
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

type TrendPoint = { label: string; ebitda: number | null; opex: number | null }

const props = defineProps<{
    hasData: boolean
    periods?: { id: number; label: string }[]
    kpis?: Kpis
    charts?: ChartsData | null
}>()

const kpis = ref<Kpis | undefined>(props.kpis)
const charts = ref<ChartsData | null | undefined>(props.charts)
const trend = ref<TrendPoint[]>([])
const trendLoading = ref(true)
const trendError = ref(false)
const selectedPeriodId = ref<string | number>(props.kpis?.period_id ?? '')
const loading = ref(false)

// Rendimiento (cierre 17-sep-2026, ronda 5 — "el dashboard tarda muchísimo en
// entrar"): la tendencia YA NO viaja con la carga inicial ni con cada cambio de
// filtro — es la MISMA para cualquier periodo (siempre "los últimos N del
// sistema"), así que se pide UNA sola vez aparte, cacheada 15 min en el backend,
// sin bloquear que KPIs/gráficas del periodo se vean de inmediato.
//
// Ronda 6 — bug real reportado: con caché fría (recién generado un periodo nuevo)
// el backend puede tardar/fallar (ver DashboardController::trend()); antes esto NO
// se atrapaba, así que `trend` se quedaba en [] PARA SIEMPRE y ChartCard mostraba
// "Sin datos disponibles" como si de verdad no hubiera nada — indistinguible de un
// error real. Ahora se distingue "cargando" (spinner) de "sin datos" (loading prop
// en ChartCard) y un fallo real ofrece reintentar en vez de fallar en silencio.
async function loadTrend() {
    trendLoading.value = true
    trendError.value = false
    try {
        const resp = await fetch('/dashboard-trend', { cache: 'no-store', headers: { Accept: 'application/json' } })
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`)
        const json = await resp.json()
        trend.value = json.trend ?? []
    } catch {
        trendError.value = true
    } finally {
        trendLoading.value = false
    }
}

onMounted(() => {
    if (!props.hasData) { trendLoading.value = false; return }
    loadTrend()
})

// Selector de periodo EN VIVO — mismo patrón que Preview.vue::fetchScopedDataset()
// (AbortController, nunca deja que una respuesta vieja pinte encima de una nueva).
// Ronda 6: además del atenuado local (opacity-50 en KPIs/gráficas), dispara la
// misma pantalla de carga de página completa que usa cualquier navegación Inertia
// — el usuario pidió experiencia consistente entre "entrar al sistema" y "cambiar
// de periodo", ninguna de las dos corre en segundo plano como generar un reporte.
let controller: AbortController | null = null
async function loadPeriod(periodId: number) {
    controller?.abort()
    const myController = new AbortController()
    controller = myController
    loading.value = true
    showRouteLoading()
    try {
        const resp = await fetch(`/dashboard-data?period_id=${periodId}`, {
            signal: myController.signal,
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        })
        const json = await resp.json()
        if (myController.signal.aborted) return
        if (json.hasData) {
            kpis.value = json.kpis
            charts.value = json.charts
        }
    } catch (e: unknown) {
        if ((e as { name?: string })?.name !== 'AbortError') throw e
    } finally {
        if (controller === myController) loading.value = false
        hideRouteLoading()
    }
}

const periodOptions = computed(() => (props.periods ?? []).map((p) => ({ value: p.id, label: p.label })))

watch(selectedPeriodId, (id) => {
    if (!id) return
    loadPeriod(Number(id))
})

const money = (v: number) => '$' + Number(v || 0).toLocaleString('es-MX', { maximumFractionDigits: 0 })

const kpiCards = computed(() => {
    if (!kpis.value) return []
    const k = kpis.value
    return [
        { label: 'EBITDA', value: money(k.ebitda), icon: TrendingUp, accent: 'text-emerald-600 dark:text-emerald-300', bg: 'bg-emerald-50 dark:bg-emerald-500/10' },
        { label: 'Margen EBITDA', value: `${k.margen_ebitda}%`, icon: Target, accent: 'text-indigo-600 dark:text-indigo-300', bg: 'bg-indigo-50 dark:bg-indigo-500/10' },
        { label: 'OPEX', value: money(k.opex), icon: Wallet, accent: 'text-rose-600 dark:text-rose-300', bg: 'bg-rose-50 dark:bg-rose-500/10' },
        { label: 'Colocación', value: money(k.colocacion), icon: Banknote, accent: 'text-sky-600 dark:text-sky-300', bg: 'bg-sky-50 dark:bg-sky-500/10' },
        { label: 'Recuperación', value: money(k.recuperacion), icon: PiggyBank, accent: 'text-teal-600 dark:text-teal-300', bg: 'bg-teal-50 dark:bg-teal-500/10' },
        { label: 'Cartera', value: money(k.cartera), icon: Landmark, accent: 'text-violet-600 dark:text-violet-300', bg: 'bg-violet-50 dark:bg-violet-500/10' },
        {
            label: 'Mora',
            value: `${k.mora_pct}%`,
            icon: k.mora_pct > 15 ? ArrowUpRight : ArrowDownRight,
            accent: k.mora_pct > 15 ? 'text-rose-600 dark:text-rose-300' : 'text-emerald-600 dark:text-emerald-300',
            bg: k.mora_pct > 15 ? 'bg-rose-50 dark:bg-rose-500/10' : 'bg-emerald-50 dark:bg-emerald-500/10',
        },
        { label: 'Colaboradores', value: String(k.colaboradores), icon: Users, accent: 'text-amber-600 dark:text-amber-300', bg: 'bg-amber-50 dark:bg-amber-500/10' },
    ]
})

const trendOptions = computed(() => ({
    chart: { toolbar: { show: false }, zoom: { enabled: false }, animations: { speed: 400 } },
    colors: ['#106A59', '#EF4444'],
    stroke: { curve: 'smooth', width: 3 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
    dataLabels: { enabled: false },
    xaxis: { categories: trend.value.map((t) => t.label), labels: { style: { fontSize: '11px' } } },
    yaxis: { labels: { formatter: (v: number) => money(v) } },
    tooltip: { y: { formatter: (v: number) => money(v) } },
    legend: { position: 'top', horizontalAlign: 'right', fontSize: '12px' },
    grid: { borderColor: '#f1f5f9' },
}))
const trendSeries = computed(() => [
    { name: 'EBITDA', data: trend.value.map((t) => t.ebitda ?? 0) },
    { name: 'OPEX', data: trend.value.map((t) => t.opex ?? 0) },
])

const categoriaOptions = computed(() => ({
    chart: { toolbar: { show: false } },
    labels: charts.value?.categoriaLabels ?? [],
    colors: charts.value?.categoriaColors ?? [],
    legend: { position: 'bottom', fontSize: '12px' },
    dataLabels: { enabled: false },
    stroke: { width: 2, colors: ['#fff'] },
    plotOptions: { pie: { donut: { size: '68%' } } },
    tooltip: { y: { formatter: (v: number) => `${v} sucursal(es)` } },
}))
const categoriaSeries = computed(() => charts.value?.categoriaCounts ?? [])

const moraOptions = computed(() => ({
    chart: { toolbar: { show: false } },
    colors: ['#EF4444'],
    plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false },
    xaxis: { categories: (charts.value?.moraBuckets ?? []).map((b) => b.label), labels: { style: { fontSize: '11px' } } },
    yaxis: { labels: { formatter: (v: number) => money(v) } },
    tooltip: { y: { formatter: (v: number) => money(v) } },
    grid: { borderColor: '#f1f5f9' },
}))
const moraSeries = computed(() => [{ name: 'Cartera vencida', data: (charts.value?.moraBuckets ?? []).map((b) => b.valor) }])

const sucursalOptions = computed(() => ({
    chart: { toolbar: { show: false } },
    colors: ['#106A59', '#1DC1A2'],
    plotOptions: { bar: { borderRadius: 5, columnWidth: '60%' } },
    dataLabels: { enabled: false },
    xaxis: { categories: (charts.value?.sucursalRows ?? []).map((r) => r.sucursal), labels: { style: { fontSize: '10px' }, rotate: -45 } },
    yaxis: { labels: { formatter: (v: number) => money(v) } },
    tooltip: { y: { formatter: (v: number) => money(v) } },
    legend: { position: 'top', horizontalAlign: 'right', fontSize: '12px' },
    grid: { borderColor: '#f1f5f9' },
}))
const sucursalSeries = computed(() => [
    { name: 'Colocación', data: (charts.value?.sucursalRows ?? []).map((r) => r.colocacion) },
    { name: 'Recuperación', data: (charts.value?.sucursalRows ?? []).map((r) => r.recuperacion) },
])

const quickLinks = [
    { label: 'Carga de archivos', href: '/historico-general', icon: FolderOpen, color: 'bg-indigo-500' },
    { label: 'Periodos', href: '/periodos', icon: CalendarRange, color: 'bg-violet-500' },
    { label: 'Colaboradores', href: '/asignaciones-empleado-sucursal', icon: Users, color: 'bg-sky-500' },
    { label: 'Reportes mensuales', href: '/reportes-mensuales', icon: FileSpreadsheet, color: 'bg-emerald-500' },
]
</script>

<template>
    <Head title="Dashboard" />
    <div class="space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- Encabezado compacto + selector de periodo en vivo -->
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-indigo-700 shadow-lg shadow-indigo-900/20">
                    <Building2 class="size-5 text-white" />
                </div>
                <div>
                    <h1 class="text-xl font-black tracking-tight text-slate-950 dark:text-slate-50">Resumen ejecutivo</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ kpis?.period_label ?? 'Sin datos' }}</p>
                </div>
            </div>

            <div v-if="hasData && periods?.length" class="w-56">
                <SelectField v-model="selectedPeriodId" :options="periodOptions" :disabled="loading" placeholder="Selecciona un periodo" />
            </div>
        </section>

        <div v-if="!hasData" class="rounded-[1.75rem] border border-dashed border-slate-300 bg-white p-10 text-center dark:border-slate-700 dark:bg-card">
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">Todavía no hay ninguna radiografía generada.</p>
            <Link href="/historico-general" class="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100 dark:bg-indigo-500/10 dark:text-indigo-300 dark:hover:bg-indigo-500/20">
                Ir a Carga de archivos →
            </Link>
        </div>

        <template v-else>
            <!-- KPIs -->
            <section :class="['grid grid-cols-2 gap-3 transition-opacity duration-200 sm:grid-cols-4 xl:grid-cols-8', loading && 'opacity-50']">
                <div
                    v-for="card in kpiCards"
                    :key="card.label"
                    class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-slate-800 dark:bg-card dark:hover:shadow-black/20"
                >
                    <div :class="['flex size-8 items-center justify-center rounded-xl', card.bg]">
                        <component :is="card.icon" :class="['size-4', card.accent]" />
                    </div>
                    <p class="mt-2 text-[11px] font-bold tracking-wide text-slate-500 uppercase dark:text-slate-400">{{ card.label }}</p>
                    <p class="mt-0.5 text-lg font-black tabular-nums text-slate-950 dark:text-slate-50">{{ card.value }}</p>
                </div>
            </section>

            <!-- Gráficas -->
            <section :class="['grid gap-4 transition-opacity duration-200 lg:grid-cols-2', loading && 'opacity-50']">
                <!-- :key fuerza a VueApexCharts a remontar (no solo "actualizar en sitio")
                     cada vez que cambia el periodo — updateOptions() de apexcharts no
                     siempre reaplica funciones (formatter) al mezclar opciones nuevas
                     sobre una instancia existente; remontar es la forma confiable de que
                     el formato de moneda del eje Y nunca se pierda tras el filtro en vivo. -->
                <div class="relative lg:col-span-2">
                    <ChartCard
                        :key="`trend-${kpis?.period_id}`"
                        title="EBITDA vs OPEX"
                        subtitle="Tendencia mensual"
                        type="area"
                        :series="trendSeries"
                        :options="trendOptions"
                        :loading="trendLoading"
                    />
                    <div v-if="trendError && !trendLoading" class="absolute top-4 right-5 flex items-center gap-2 text-[11px] font-semibold text-rose-500">
                        No se pudo cargar la tendencia.
                        <button type="button" class="rounded-full bg-rose-50 px-2.5 py-1 text-rose-600 transition duration-200 hover:bg-rose-100" @click="loadTrend">
                            Reintentar
                        </button>
                    </div>
                </div>
                <ChartCard :key="`categoria-${kpis?.period_id}`" title="Categoría EBITDA por sucursal" subtitle="Sucursales según su desempeño" type="donut" :series="categoriaSeries" :options="categoriaOptions" />
                <ChartCard :key="`mora-${kpis?.period_id}`" title="Cartera vencida por antigüedad" subtitle="Mora por bucket" type="bar" :series="moraSeries" :options="moraOptions" />
                <ChartCard
                    :key="`sucursal-${kpis?.period_id}`"
                    title="Colocación vs. recuperación"
                    subtitle="Por sucursal"
                    type="bar"
                    :series="sucursalSeries"
                    :options="sucursalOptions"
                    class="lg:col-span-2"
                    :height="320"
                />
            </section>
        </template>

        <!-- Accesos rápidos -->
        <section class="flex flex-wrap gap-3">
            <Link
                v-for="link in quickLinks"
                :key="link.href"
                :href="link.href"
                class="group flex items-center gap-2.5 rounded-2xl border border-slate-200 bg-white px-4 py-2.5 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md dark:border-slate-800 dark:bg-card dark:hover:border-slate-700 dark:hover:shadow-black/20"
            >
                <div :class="['flex size-7 shrink-0 items-center justify-center rounded-lg', link.color]">
                    <component :is="link.icon" class="size-3.5 text-white" />
                </div>
                <span class="text-xs font-bold text-slate-700 transition-colors group-hover:text-slate-950 dark:text-slate-300 dark:group-hover:text-slate-50">{{ link.label }}</span>
            </Link>
        </section>
    </div>
</template>
