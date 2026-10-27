<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import {
    CalendarRange, Eye, FileBarChart2, FileSpreadsheet, FileText,
    GitCompareArrows, Search, Building2, UserRound, ShieldCheck,
} from 'lucide-vue-next'
import { computed, ref } from 'vue'

import AppLayout from '@/layouts/AppLayout.vue'

type GeneratedReport = {
    id: number | string
    name: string
    period_id: number
    period: string
    period_code: string
    period_type?: string | null
    period_start?: string | null
    period_end?: string | null
    comparison_period?: string | null
    comparison_period_start?: string | null
    comparison_period_end?: string | null
    type: string
    scope: string
    scope_detail?: string | null
    generated_at: string
    generated_by?: number | null
    status: string
    excel_url: string
    pdf_url: string
    preview_url: string
}

/** 27-oct-2026 — qué reportes ve este usuario: por autor (su rol) y por sucursal (su usuario). */
type ReportVisibility = {
    mode: 'all' | 'own' | 'none'
    general_scope: boolean
    branches_label: string
    text: string
}

const props = withDefaults(
    defineProps<{
        message: string
        generatedReports?: GeneratedReport[]
        visibility?: ReportVisibility | null
    }>(),
    {
        generatedReports: () => [],
        visibility: null,
    },
)

defineOptions({
    layout: AppLayout,
})

const STATUS_LABELS: Record<string, string> = {
    generated:   'Generado',
    processing:  'Procesando',
    pending:     'Pendiente',
    failed:      'Error',
    cancelled:   'Cancelado',
    expired:     'Expirado',
    invalidated: 'Desactualizado',
}
const STATUS_DOT: Record<string, string> = {
    generated:   'bg-emerald-500',
    processing:  'bg-sky-500',
    pending:     'bg-amber-500',
    failed:      'bg-rose-500',
    cancelled:   'bg-slate-400',
    expired:     'bg-slate-300',
    invalidated: 'bg-amber-500',
}
const STATUS_BADGE: Record<string, string> = {
    generated:   'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    processing:  'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    pending:     'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    failed:      'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    cancelled:   'bg-slate-100 text-slate-500 dark:bg-slate-800/60 dark:text-slate-400',
    expired:     'bg-slate-50 text-slate-400 dark:bg-slate-800/40 dark:text-slate-500',
    invalidated: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
}
const statusLabel = (s: string) => STATUS_LABELS[s?.toLowerCase()] ?? s
const statusDot   = (s: string) => STATUS_DOT[s?.toLowerCase()] ?? 'bg-slate-400'
const statusBadge = (s: string) => STATUS_BADGE[s?.toLowerCase()] ?? 'bg-slate-100 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300'

const SCOPE_LABELS: Record<string, string> = {
    general:  'General',
    branch:   'Por sucursal',
    employee: 'Por gestor',
}
const SCOPE_ICON: Record<string, typeof Building2> = {
    branch: Building2,
    employee: UserRound,
}
const scopeLabel = (s: string) => SCOPE_LABELS[s?.toLowerCase()] ?? s

const PERIOD_TYPE_LABELS: Record<string, string> = {
    monthly: 'Mensual',
    weekly: 'Semanal',
    bimonthly: 'Bimestral',
    quarterly: 'Trimestral',
    yearly: 'Anual',
}
const periodTypeLabel = (t?: string | null) => (t ? (PERIOD_TYPE_LABELS[t] ?? t) : null)

const searchQuery  = ref('')
const filterStatus = ref('')

const filteredReports = computed(() => {
    const q = searchQuery.value.trim().toLowerCase()
    const s = filterStatus.value.toLowerCase()

    return props.generatedReports.filter((r) => {
        const matchSearch = !q
            || r.name?.toLowerCase().includes(q)
            || r.period?.toLowerCase().includes(q)
            || r.type?.toLowerCase().includes(q)
        const matchStatus = !s || r.status?.toLowerCase() === s

        return matchSearch && matchStatus
    })
})

// Rango de fechas legible por tarjeta — "de qué mes/semanas es" (pedido explícito:
// más info, nunca solo el nombre del periodo). Nunca inventa nada — solo formatea
// lo que ya viene del backend (periods.start_date/end_date reales).
function dateRange(startDate?: string | null, endDate?: string | null): string | null {
    if (!startDate && !endDate) {
return null
}

    if (startDate && endDate) {
return `${startDate} → ${endDate}`
}

    return startDate || endDate || null
}
</script>

<template>
    <Head title="Reportes por periodo" />

    <div class="space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- Encabezado compacto (cierre 17-sep-2026 ronda 5 — se quitó el banner
             oscuro grande a petición explícita del usuario). -->
        <section class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between" data-tour="reportes-header">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-xl border bg-accent text-primary">
                    <FileBarChart2 class="size-5" />
                </div>
                <div>
                    <p class="app-kicker">Análisis</p>
                    <h1 class="app-title">Reportes mensuales</h1>
                    <p class="app-subtitle mt-0.5">{{ message }}</p>
                </div>
            </div>
            <span class="app-pill app-pill-success w-fit px-3 py-1 text-xs" aria-live="polite">
                {{ filteredReports.length }} de {{ generatedReports.length }} disponibles
            </span>
        </section>

        <!-- Alcance de consulta: por autor (rol) + por sucursal (usuario) -->
        <p
            v-if="visibility"
            class="flex items-start gap-2 rounded-xl border px-3 py-2 text-xs"
            :class="visibility.mode === 'none' ? 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200' : 'border-border bg-muted/40 text-muted-foreground'"
            role="note"
        >
            <ShieldCheck class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <span>
                {{ visibility.text }}
                <template v-if="visibility.mode === 'own'"> Para ver los de otros usuarios, un administrador debe habilitar «Ver reportes de todos» en Roles y permisos.</template>
                <template v-else-if="visibility.mode === 'none'"> Un administrador puede habilitar «Ver reportes propios» o «Ver reportes de todos» en Roles y permisos.</template>
            </span>
        </p>

        <!-- Filtros -->
        <section v-if="generatedReports.length" class="flex flex-col gap-3 rounded-xl border bg-card p-2 shadow-soft-xs lg:flex-row lg:items-center" data-tour="reportes-filters">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
                <input
                    v-model="searchQuery"
                    type="search"
                    placeholder="Buscar por nombre, periodo o tipo…"
                    aria-label="Buscar reportes"
                    class="app-input h-10 rounded-lg pl-10"
                />
            </div>
            <div class="scrollbar-none -mx-2 flex gap-1.5 overflow-x-auto px-2 lg:mx-0 lg:flex-wrap lg:px-0" role="group" aria-label="Filtrar por estado">
                <button
                    type="button"
                    :aria-pressed="filterStatus === ''"
                    :class="filterStatus === '' ? 'bg-foreground text-background' : 'bg-muted text-muted-foreground hover:text-foreground'"
                    class="shrink-0 rounded-lg px-3 py-2 text-xs font-semibold transition"
                    @click="filterStatus = ''"
                >
                    Todos
                </button>
                <button
                    v-for="(label, key) in STATUS_LABELS"
                    :key="key"
                    type="button"
                    :aria-pressed="filterStatus === key"
                    :class="filterStatus === key ? 'bg-foreground text-background' : 'bg-muted text-muted-foreground hover:text-foreground'"
                    class="shrink-0 rounded-lg px-3 py-2 text-xs font-semibold transition"
                    @click="filterStatus = key"
                >
                    {{ label }}
                </button>
            </div>
        </section>

        <!-- Tarjetas -->
        <section v-if="filteredReports.length" class="app-stagger grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="report in filteredReports"
                :key="report.id"
                class="app-hover-lift flex flex-col rounded-2xl border bg-card p-5 shadow-soft-xs"
            >
                <div class="flex items-start justify-between gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="statusBadge(report.status)">
                        <span class="size-1.5 rounded-full" :class="statusDot(report.status)" />
                        {{ statusLabel(report.status) }}
                    </span>
                    <span v-if="report.period_type" class="app-pill app-pill-muted uppercase">
                        {{ periodTypeLabel(report.period_type) }}
                    </span>
                </div>

                <h3 class="mt-3 text-sm leading-snug font-semibold text-foreground" :title="report.name">{{ report.name }}</h3>

                <div class="mt-2.5 space-y-1.5 text-xs text-muted-foreground">
                    <p class="flex items-center gap-1.5">
                        <CalendarRange class="size-3.5 shrink-0 text-slate-400" />
                        <span class="font-semibold text-slate-700 dark:text-slate-200">{{ report.period }}</span>
                        <span v-if="dateRange(report.period_start, report.period_end)" class="text-slate-400">· {{ dateRange(report.period_start, report.period_end) }}</span>
                    </p>
                    <p v-if="report.comparison_period" class="flex items-center gap-1.5">
                        <GitCompareArrows class="size-3.5 shrink-0 text-slate-400" />
                        vs <span class="font-semibold text-slate-700 dark:text-slate-200">{{ report.comparison_period }}</span>
                        <span v-if="dateRange(report.comparison_period_start, report.comparison_period_end)" class="text-slate-400">· {{ dateRange(report.comparison_period_start, report.comparison_period_end) }}</span>
                    </p>
                    <p class="flex items-center gap-1.5">
                        <component :is="SCOPE_ICON[report.scope] ?? FileText" class="size-3.5 shrink-0 text-slate-400" />
                        {{ report.type }} · {{ scopeLabel(report.scope) }}
                        <template v-if="report.scope_detail">— {{ report.scope_detail }}</template>
                    </p>
                    <p class="text-slate-400">Generado {{ report.generated_at ?? '—' }}</p>
                </div>

                <!-- Fase 8.9: acciones con texto (antes solo iconos sin etiqueta). -->
                <div class="mt-auto flex flex-wrap items-center gap-2 border-t pt-4">
                    <a :href="report.preview_url" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3 text-sm font-semibold text-primary-foreground transition hover:bg-primary/90" :aria-label="`Ver ${report.name}`">
                        <Eye class="size-4" aria-hidden="true" /> Ver
                    </a>
                    <a :href="report.excel_url" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium text-foreground transition hover:bg-muted" :aria-label="`Descargar Excel de ${report.name}`">
                        <FileSpreadsheet class="size-4 text-success" aria-hidden="true" /> Excel
                    </a>
                    <a :href="report.pdf_url" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium text-foreground transition hover:bg-muted" :aria-label="`Descargar PDF de ${report.name}`">
                        <FileText class="size-4 text-danger" aria-hidden="true" /> PDF
                    </a>
                </div>
            </article>
        </section>

        <div v-if="!generatedReports.length" class="rounded-2xl border border-dashed bg-card p-10 text-center text-sm text-muted-foreground">
            <p class="font-semibold text-foreground">{{ visibility?.mode === 'none' ? 'Tu rol no tiene permiso para ver reportes.' : visibility?.mode === 'own' ? 'Aún no has generado reportes.' : 'Aún no hay reportes generados.' }}</p>
            <p v-if="visibility?.mode !== 'none'" class="mt-1">Se generan desde «Carga de archivos», en la etapa «Generar reporte» del periodo.</p>
        </div>
        <div v-else-if="!filteredReports.length" class="rounded-2xl border border-dashed bg-card p-10 text-center text-sm text-muted-foreground">
            <p>No hay reportes que coincidan con los filtros aplicados.</p>
            <button type="button" class="mt-3 inline-flex h-9 items-center rounded-lg border border-border px-3 text-sm font-medium text-foreground hover:bg-muted" @click="searchQuery = ''; filterStatus = ''">Limpiar filtros</button>
        </div>
    </div>
</template>
