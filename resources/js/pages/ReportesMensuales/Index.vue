<script setup lang="ts">
import { computed, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import {
    CalendarRange, Eye, FileBarChart2, FileSpreadsheet, FileText,
    GitCompareArrows, Search, Building2, UserRound,
} from 'lucide-vue-next'

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

const props = withDefaults(
    defineProps<{
        message: string
        generatedReports?: GeneratedReport[]
    }>(),
    {
        generatedReports: () => [],
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
    generated:   'bg-emerald-50 text-emerald-700',
    processing:  'bg-sky-50 text-sky-700',
    pending:     'bg-amber-50 text-amber-700',
    failed:      'bg-rose-50 text-rose-700',
    cancelled:   'bg-slate-100 text-slate-500',
    expired:     'bg-slate-50 text-slate-400',
    invalidated: 'bg-amber-50 text-amber-700',
}
const statusLabel = (s: string) => STATUS_LABELS[s?.toLowerCase()] ?? s
const statusDot   = (s: string) => STATUS_DOT[s?.toLowerCase()] ?? 'bg-slate-400'
const statusBadge = (s: string) => STATUS_BADGE[s?.toLowerCase()] ?? 'bg-slate-100 text-slate-600'

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
    if (!startDate && !endDate) return null
    if (startDate && endDate) return `${startDate} → ${endDate}`
    return startDate || endDate || null
}
</script>

<template>
    <Head title="Reportes por periodo" />

    <div class="space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- Encabezado compacto (cierre 17-sep-2026 ronda 5 — se quitó el banner
             oscuro grande a petición explícita del usuario). -->
        <section class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-600 shadow-lg shadow-emerald-900/20">
                    <FileBarChart2 class="size-5 text-white" />
                </div>
                <div>
                    <h1 class="text-xl font-black tracking-tight text-slate-950">Reportes generados</h1>
                    <p class="text-xs text-slate-500">{{ message }}</p>
                </div>
            </div>
            <span class="w-fit rounded-full bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-700">
                {{ filteredReports.length }} de {{ generatedReports.length }} disponibles
            </span>
        </section>

        <!-- Filtros -->
        <section v-if="generatedReports.length" class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-slate-400" />
                <input
                    v-model="searchQuery"
                    type="search"
                    placeholder="Buscar por nombre, periodo o tipo…"
                    class="h-11 w-full rounded-2xl border border-slate-200 bg-white pr-4 pl-10 text-sm shadow-sm transition focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
                />
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button
                    type="button"
                    :class="filterStatus === '' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                    class="rounded-xl px-3 py-2 text-xs font-bold transition"
                    @click="filterStatus = ''"
                >
                    Todos
                </button>
                <button
                    v-for="(label, key) in STATUS_LABELS"
                    :key="key"
                    type="button"
                    :class="filterStatus === key ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                    class="rounded-xl px-3 py-2 text-xs font-bold transition"
                    @click="filterStatus = key"
                >
                    {{ label }}
                </button>
            </div>
        </section>

        <!-- Tarjetas -->
        <section v-if="filteredReports.length" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="report in filteredReports"
                :key="report.id"
                class="group flex flex-col rounded-[1.75rem] border border-slate-200 bg-white p-5 shadow-sm transition duration-200 hover:-translate-y-1 hover:border-slate-300 hover:shadow-lg"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-1.5 text-xs font-bold text-slate-400">
                        <span class="size-2 rounded-full" :class="statusDot(report.status)" />
                        {{ statusLabel(report.status) }}
                    </div>
                    <span v-if="report.period_type" class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-black tracking-wide text-slate-500 uppercase">
                        {{ periodTypeLabel(report.period_type) }}
                    </span>
                </div>

                <h3 class="mt-2.5 text-sm leading-snug font-black text-slate-950" :title="report.name">{{ report.name }}</h3>

                <div class="mt-3 space-y-1.5 text-xs text-slate-500">
                    <p class="flex items-center gap-1.5">
                        <CalendarRange class="size-3.5 shrink-0 text-slate-400" />
                        <span class="font-semibold text-slate-700">{{ report.period }}</span>
                        <span v-if="dateRange(report.period_start, report.period_end)" class="text-slate-400">· {{ dateRange(report.period_start, report.period_end) }}</span>
                    </p>
                    <p v-if="report.comparison_period" class="flex items-center gap-1.5">
                        <GitCompareArrows class="size-3.5 shrink-0 text-slate-400" />
                        vs <span class="font-semibold text-slate-700">{{ report.comparison_period }}</span>
                        <span v-if="dateRange(report.comparison_period_start, report.comparison_period_end)" class="text-slate-400">· {{ dateRange(report.comparison_period_start, report.comparison_period_end) }}</span>
                    </p>
                    <p class="flex items-center gap-1.5">
                        <component :is="SCOPE_ICON[report.scope] ?? FileText" class="size-3.5 shrink-0 text-slate-400" />
                        {{ report.type }} · {{ scopeLabel(report.scope) }}
                        <template v-if="report.scope_detail">— {{ report.scope_detail }}</template>
                    </p>
                    <p class="text-slate-400">Generado {{ report.generated_at ?? '—' }}</p>
                </div>

                <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4">
                    <span class="rounded-full px-2.5 py-1 text-[10px] font-black" :class="statusBadge(report.status)">{{ statusLabel(report.status) }}</span>
                    <div class="flex gap-1.5">
                        <a :href="report.preview_url" class="inline-flex size-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-600" title="Ver">
                            <Eye class="size-4" />
                        </a>
                        <a :href="report.excel_url" class="inline-flex size-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-emerald-300 hover:bg-emerald-50 hover:text-emerald-600" title="Excel">
                            <FileSpreadsheet class="size-4" />
                        </a>
                        <a :href="report.pdf_url" class="inline-flex size-9 items-center justify-center rounded-xl border border-slate-200 text-slate-500 transition hover:border-rose-300 hover:bg-rose-50 hover:text-rose-600" title="PDF">
                            <FileText class="size-4" />
                        </a>
                    </div>
                </div>
            </article>
        </section>

        <div v-if="!generatedReports.length" class="rounded-[1.75rem] border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
            Aún no hay reportes generados. Genéralos desde Histórico general.
        </div>
        <div v-else-if="!filteredReports.length" class="rounded-[1.75rem] border border-dashed border-slate-300 bg-white p-10 text-center text-sm text-slate-500">
            No hay reportes que coincidan con los filtros aplicados.
        </div>
    </div>
</template>
