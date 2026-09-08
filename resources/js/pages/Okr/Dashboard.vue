<script setup lang="ts">
// Módulo OKR — Dashboard (rediseño 08-sep-2026, secciones L-N/AG del pedido).
// Ancho completo del contenedor (nunca max-w-7xl con espacio muerto lateral).
import { computed, reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { AlertTriangle, Building2, Gauge, History, LayoutGrid, List, Plus, ShieldAlert, Target, Users, X, XCircle } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import { Button } from '@/components/ui/button'
import OkrFilters from '@/components/okr/OkrFilters.vue'
import OkrStatCard from '@/components/okr/OkrStatCard.vue'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import OkrProgressBar from '@/components/okr/OkrProgressBar.vue'
import OkrSparkline from '@/components/okr/OkrSparkline.vue'
import OkrAttentionCenter from '@/components/okr/OkrAttentionCenter.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    objectives: any[]
    has_any_objectives: boolean
    cards: {
        active: number; risk: number; not_met: number; avg_compliance: number
        branches_with_okr: number; employees_with_okr: number; total_branches: number; total_employees: number
    }
    filters: {
        branches: { id: number; name: string }[]
        statuses: string[]
        kpis: { id: number; name: string }[]
        responsibles: { id: number; name: string }[]
        periods: { id: number; label: string }[]
    }
    query: Record<string, string | undefined>
}>()

const filterState = reactive({
    branch_id: props.query.branch_id ?? '',
    employee_id: props.query.employee_id ?? '',
    status: props.query.status ?? '',
    search: props.query.search ?? '',
    period_id: props.query.period_id ?? '',
    start_date: props.query.start_date ?? null,
    end_date: props.query.end_date ?? null,
    kpi_id: props.query.kpi_id ?? '',
    responsible_user_id: props.query.responsible_user_id ?? '',
})

function applyFilters() {
    router.get('/okr', { ...filterState }, { preserveState: true, replace: true })
}

function clearFilters() {
    Object.assign(filterState, { branch_id: '', employee_id: '', status: '', search: '', period_id: '', start_date: null, end_date: null, kpi_id: '', responsible_user_id: '' })
    router.get('/okr', {}, { replace: true })
}

const statusLabel: Record<string, string> = { draft: 'Borrador', active: 'Activo', closed: 'Cerrado', cancelled: 'Cancelado' }

// CORRECCIÓN 09-sep-2026 (punto 13 de la auditoría) — bug de diseño: el
// comparador anterior usaba SOLO `a` (`a.health_status === 'off_track' ? -1
// : 1`), nunca comparaba contra `b` — un sort() inválido (no determinista,
// no ordena realmente por severidad). Ahora hay una prioridad real:
// fuera de trayectoria > riesgo > en trayectoria > adelantado > sin semáforo,
// y como desempate: fecha de término más cercana, luego id descendente.
const HEALTH_PRIORITY: Record<string, number> = { off_track: 0, risk: 1, on_track: 2, ahead: 3 }
function healthPriority(status: string | null): number {
    return status !== null && status in HEALTH_PRIORITY ? HEALTH_PRIORITY[status] : 4
}
const sortedObjectives = computed(() => [...props.objectives].sort((a, b) => {
    const byHealth = healthPriority(a.health_status) - healthPriority(b.health_status)
    if (byHealth !== 0) return byHealth
    const byEndDate = (a.end_date ?? '9999-12-31').localeCompare(b.end_date ?? '9999-12-31')
    if (byEndDate !== 0) return byEndDate
    return b.id - a.id
}))

// CORRECCIÓN (punto 14) — antes "No hay OKR todavía" podía salir con
// filtros que simplemente no matchearon nada, aunque sí existieran OKR en el
// sistema. `has_any_objectives` viene del backend SIN los filtros de este
// request (ver DashboardController) — nunca se infiere del listado filtrado.
const hasAnyObjective = computed(() => props.has_any_objectives)

// ── Chips de filtros activos (punto 23) ──
const activeFilterChips = computed(() => {
    const chips: { key: string; label: string }[] = []
    if (filterState.branch_id) chips.push({ key: 'branch_id', label: props.filters.branches.find((b) => String(b.id) === filterState.branch_id)?.name ?? 'Sucursal' })
    if (filterState.status) chips.push({ key: 'status', label: statusLabel[filterState.status] ?? filterState.status })
    if (filterState.kpi_id) chips.push({ key: 'kpi_id', label: props.filters.kpis.find((k) => String(k.id) === filterState.kpi_id)?.name ?? 'KPI' })
    if (filterState.responsible_user_id) chips.push({ key: 'responsible_user_id', label: props.filters.responsibles.find((u) => String(u.id) === filterState.responsible_user_id)?.name ?? 'Responsable' })
    if (filterState.period_id) chips.push({ key: 'period_id', label: props.filters.periods.find((p) => String(p.id) === filterState.period_id)?.label ?? 'Periodo' })
    if (filterState.start_date) chips.push({ key: 'start_date', label: `Desde ${filterState.start_date}` })
    if (filterState.end_date) chips.push({ key: 'end_date', label: `Hasta ${filterState.end_date}` })
    if (filterState.search) chips.push({ key: 'search', label: `"${filterState.search}"` })
    return chips
})
function removeChip(key: string) {
    ;(filterState as any)[key] = key === 'start_date' || key === 'end_date' ? null : ''
    applyFilters()
}

// ── Selector de vista (punto 15) — preferencia SOLO de UX, en el query. ──
const view = ref<'table' | 'cards' | 'risk'>('table')
const riskObjectives = computed(() => sortedObjectives.value.filter((o) => ['off_track', 'risk'].includes(o.health_status)))
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <!-- Header ejecutivo -->
        <section class="overflow-hidden rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-300/40 sm:p-8 dark:shadow-none">
            <div class="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
                <div class="flex items-start gap-4">
                    <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-500">
                        <Target class="size-6 text-white" />
                    </div>
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.28em] text-indigo-300">Objectives &amp; Key Results</p>
                        <h1 class="mt-1 text-3xl font-black tracking-tight sm:text-4xl">OKR</h1>
                        <p class="mt-2 max-w-xl text-sm leading-6 text-slate-300">
                            Define, mide y anticipa el cumplimiento de objetivos por sucursal y colaborador.
                        </p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Link href="/okr/history">
                        <Button variant="outline" class="h-11 gap-2 rounded-2xl border-white/20 bg-white/5 text-white hover:bg-white/10">
                            <History class="size-4" /> Histórico
                        </Button>
                    </Link>
                    <Link href="/okr/create">
                        <Button class="h-11 gap-2 rounded-2xl bg-indigo-500 text-white shadow-lg shadow-indigo-500/30 hover:bg-indigo-400">
                            <Plus class="size-4" /> Asignar OKR
                        </Button>
                    </Link>
                </div>
            </div>
        </section>

        <OkrFilters
            v-model="filterState"
            :branches="filters.branches"
            :statuses="filters.statuses"
            :kpis="filters.kpis"
            :responsibles="filters.responsibles"
            :periods="filters.periods"
            @apply="applyFilters"
            @clear="clearFilters"
        />

        <!-- Chips de filtros activos -->
        <div v-if="activeFilterChips.length" class="flex flex-wrap items-center gap-2">
            <button
                v-for="chip in activeFilterChips" :key="chip.key"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground transition hover:border-destructive/40 hover:bg-destructive/5 hover:text-destructive"
                @click="removeChip(chip.key)"
            >
                {{ chip.label }} <X class="size-3" />
            </button>
            <Button variant="ghost" size="sm" class="h-7 text-xs text-muted-foreground" @click="clearFilters">Limpiar todo</Button>
        </div>

        <OkrAttentionCenter :objectives="sortedObjectives" />

        <!-- Cards ejecutivas -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <OkrStatCard :icon="Target" label="OKR activos" :value="cards.active" hint="Objetivos vigentes" tone="primary" />
            <OkrStatCard :icon="AlertTriangle" label="OKR en riesgo" :value="cards.risk" hint="Requieren atención" tone="warning" />
            <OkrStatCard :icon="XCircle" label="No cumplidos" :value="cards.not_met" hint="Cerrados sin alcanzar la meta" tone="danger" />
            <OkrStatCard :icon="Gauge" label="Cumplimiento promedio" :value="`${cards.avg_compliance}%`" hint="Alcance actual" tone="success" />
            <OkrStatCard :icon="Building2" label="Sucursales con OKR" :value="`${cards.branches_with_okr}/${cards.total_branches}`" hint="Del alcance filtrado" />
            <OkrStatCard :icon="Users" label="Colaboradores con OKR" :value="`${cards.employees_with_okr}/${cards.total_employees}`" hint="Total de colaboradores activos" global />
        </div>

        <!-- Selector de vista -->
        <div class="flex items-center gap-1 rounded-2xl border border-border bg-card p-1 w-fit">
            <button type="button" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition" :class="view === 'table' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'" @click="view = 'table'">
                <List class="size-3.5" /> Tabla
            </button>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition" :class="view === 'cards' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'" @click="view = 'cards'">
                <LayoutGrid class="size-3.5" /> Cards
            </button>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 text-xs font-semibold transition" :class="view === 'risk' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'" @click="view = 'risk'">
                <ShieldAlert class="size-3.5" /> Riesgo ({{ riskObjectives.length }})
            </button>
        </div>

        <!-- Vista: Cards -->
        <div v-if="view === 'cards'">
            <div v-if="sortedObjectives.length" class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link v-for="o in sortedObjectives" :key="o.id" :href="`/okr/${o.id}`" class="app-card block space-y-3 p-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg">
                    <div class="flex items-start justify-between gap-2">
                        <OkrStatusBadge kind="health" :value="o.health_status" />
                        <OkrStatusBadge kind="lifecycle" :value="o.lifecycle_status" />
                    </div>
                    <p class="line-clamp-2 text-sm font-bold text-foreground">{{ o.title }}</p>
                    <p class="text-xs text-muted-foreground">{{ o.branch ?? o.employee ?? '—' }} · {{ o.responsible ?? 'Sin responsable' }}</p>
                    <div class="flex items-center justify-between gap-2">
                        <OkrProgressBar :value="o.compliance" show-label />
                        <OkrSparkline :values="o.trend ?? []" />
                    </div>
                    <div class="flex items-center justify-between text-xs text-muted-foreground">
                        <span>Semana {{ o.current_week }}/{{ o.duration_weeks }}</span>
                        <span>Vence {{ o.end_date }}</span>
                    </div>
                </Link>
            </div>
            <AppEmptyState
                v-else-if="!hasAnyObjective"
                title="No hay OKR todavía"
                message="Comienza creando el primer objetivo para una sucursal o colaborador."
            >
                <Link href="/okr/create"><Button class="h-11 gap-2 rounded-2xl"><Plus class="size-4" /> Asignar OKR</Button></Link>
            </AppEmptyState>
            <AppEmptyState v-else title="No encontramos OKR con esos filtros" message="Ajusta o limpia los filtros para ver más resultados.">
                <Button variant="outline" class="h-11 rounded-2xl" @click="clearFilters">Limpiar filtros</Button>
            </AppEmptyState>
        </div>

        <!-- Vista: Riesgo -->
        <div v-else-if="view === 'risk'" class="app-table-wrap">
            <div class="app-table-content divide-y divide-border">
                <Link v-for="o in riskObjectives" :key="o.id" :href="`/okr/${o.id}`" class="flex items-center justify-between gap-3 p-4 transition hover:bg-muted/30">
                    <div class="flex items-center gap-3">
                        <OkrStatusBadge kind="health" :value="o.health_status" />
                        <div>
                            <p class="text-sm font-semibold text-foreground">{{ o.title }}</p>
                            <p class="text-xs text-muted-foreground">{{ o.branch ?? o.employee ?? '—' }} · {{ o.responsible ?? '—' }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold tabular-nums text-foreground">{{ o.compliance }}%</p>
                        <p class="text-xs text-muted-foreground">Semana {{ o.current_week }}/{{ o.duration_weeks }}</p>
                    </div>
                </Link>
                <AppEmptyState v-if="!riskObjectives.length" title="Nada en riesgo" message="Ningún OKR está fuera de trayectoria o en riesgo con los filtros actuales." />
            </div>
        </div>

        <!-- Vista: Tabla -->
        <div v-else class="app-table-wrap">
            <div class="app-table-content">
                <table v-if="sortedObjectives.length" class="w-full min-w-[960px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Objective</th>
                            <th class="px-4 py-3 text-left font-semibold">Sucursal / Colaborador</th>
                            <th class="px-4 py-3 text-left font-semibold">Responsable</th>
                            <th class="px-4 py-3 text-left font-semibold">Plazo</th>
                            <th class="px-4 py-3 text-left font-semibold">Progreso</th>
                            <th class="px-4 py-3 text-left font-semibold">Estado</th>
                            <th class="px-4 py-3 text-left font-semibold">Semáforo</th>
                            <th class="px-4 py-3 text-left font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in sortedObjectives" :key="o.id" class="border-t border-border transition-colors hover:bg-muted/30">
                            <td class="px-4 py-3 font-semibold text-foreground">{{ o.title }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ o.branch ?? o.employee ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ o.responsible ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted-foreground">Semana {{ o.current_week }}/{{ o.duration_weeks }}</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <OkrProgressBar :value="o.compliance" />
                                    <span class="shrink-0 text-xs font-semibold tabular-nums text-muted-foreground">{{ o.compliance }}%</span>
                                </div>
                            </td>
                            <td class="px-4 py-3"><OkrStatusBadge kind="lifecycle" :value="o.lifecycle_status" /></td>
                            <td class="px-4 py-3"><OkrStatusBadge kind="health" :value="o.health_status" /></td>
                            <td class="px-4 py-3">
                                <Link :href="`/okr/${o.id}`" class="text-xs font-bold text-primary transition hover:underline">Ver detalle →</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <AppEmptyState
                    v-else-if="!hasAnyObjective"
                    title="No hay OKR todavía"
                    message="Comienza creando el primer objetivo para una sucursal o colaborador."
                >
                    <Link href="/okr/create">
                        <Button class="h-11 gap-2 rounded-2xl"><Plus class="size-4" /> Asignar OKR</Button>
                    </Link>
                </AppEmptyState>

                <AppEmptyState
                    v-else
                    title="No encontramos OKR con esos filtros"
                    message="Ajusta o limpia los filtros para ver más resultados."
                >
                    <Button variant="outline" class="h-11 rounded-2xl" @click="clearFilters">Limpiar filtros</Button>
                </AppEmptyState>
            </div>
        </div>
    </div>
</template>
