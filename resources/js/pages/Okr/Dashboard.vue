<script setup lang="ts">
// Módulo OKR — Dashboard. Reconstruido 10-sep-2026 para reproducir 1:1
// docs/imagenesOKR/1.png y 2.png (auditoría de esa fecha, secciones 2-5):
// hero compacto, UNA barra de filtros, 6 cards en una sola fila, y grid
// inferior 67/33 (Objetivos y Key Results | Cumplimiento general + Riesgo y
// proyección). Sin Attention Center, sin sparklines, sin selector de vistas,
// sin tabs — esas ideas del rediseño anterior NO están en la referencia.
import { computed, reactive, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { AlertTriangle, Building2, Gauge, History, Target, UserPlus, Users, XCircle } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import { Button } from '@/components/ui/button'
import OkrFilters from '@/components/okr/OkrFilters.vue'
import OkrStatCard from '@/components/okr/OkrStatCard.vue'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import OkrProgressBar from '@/components/okr/OkrProgressBar.vue'
import OkrComplianceDonut from '@/components/okr/OkrComplianceDonut.vue'
import OkrRiskProjectionPanel from '@/components/okr/OkrRiskProjectionPanel.vue'
import OkrAssignDialog from '@/components/okr/wizard/OkrAssignDialog.vue'
import { formatFriendlyDate } from '@/lib/okrFormat'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    objectives: any[]
    has_any_objectives: boolean
    cards: {
        active: number; risk: number; not_met: number; avg_compliance: number
        branches_with_okr: number; employees_with_okr: number; total_branches: number; total_employees: number
    }
    compliance_breakdown: { total: number; completed: number; completed_pct: number; on_track: number; on_track_pct: number; at_risk: number; at_risk_pct: number; off_track: number; off_track_pct: number }
    risk_projection: { id: number; label: string; health_status: string | null; projected_compliance: number | null }[]
    filters: {
        branches: { id: number; name: string }[]
        statuses: string[]
        kpis: { id: number; name: string }[]
        responsibles: { id: number; name: string }[]
        periods: { id: number; label: string }[]
    }
    query: Record<string, string | undefined>
    wizardBranches: { id: number; name: string }[]
    wizardKpis: any[]
    wizardCurrentUser: { id: number; full_name: string }
    wizardUsers: { id: number; full_name: string }[]
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
const hasAnyObjective = computed(() => props.has_any_objectives)

// Orden por severidad de semáforo — determinista (compara health_status de
// AMBAS filas, nunca solo una).
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

const assignOpen = ref(false)
</script>

<template>
    <div class="w-full space-y-4 px-4 py-4 sm:px-6 sm:py-6 lg:px-8">
        <!-- Hero compacto -->
        <section class="overflow-hidden rounded-2xl bg-slate-950 px-5 py-5 text-white shadow-md sm:px-7">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.2em] text-indigo-300">
                        <Target class="size-3.5" /> Sistema Reportes
                    </p>
                    <h1 class="mt-1 text-2xl font-bold tracking-tight">OKR (Objective Key Result)</h1>
                    <p class="mt-1 text-sm text-slate-300">Gestiona objetivos, resultados clave y seguimiento por sucursal y colaborador.</p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <Button class="h-10 gap-2 rounded-xl bg-emerald-500 text-white hover:bg-emerald-400" @click="assignOpen = true">
                        <UserPlus class="size-4" /> Asignar OKR
                    </Button>
                    <Link href="/okr/history">
                        <Button variant="outline" class="h-10 gap-2 rounded-xl border-white/20 bg-white/5 text-white hover:bg-white/10">
                            <History class="size-4" /> Histórico
                        </Button>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Filtros (una sola barra) -->
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

        <!-- 6 cards en una sola fila -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <OkrStatCard :icon="Target" label="OKR activos" :value="cards.active" tone="primary" />
            <OkrStatCard :icon="AlertTriangle" label="OKR en riesgo" :value="cards.risk" tone="warning" />
            <OkrStatCard :icon="XCircle" label="No cumplidos" :value="cards.not_met" tone="danger" />
            <OkrStatCard :icon="Gauge" label="% cumplimiento promedio" :value="`${cards.avg_compliance}%`" tone="success" />
            <OkrStatCard :icon="Building2" label="Sucursales con OKR" :value="`${cards.branches_with_okr} / ${cards.total_branches}`" />
            <OkrStatCard :icon="Users" label="Colaboradores con OKR individual" :value="`${cards.employees_with_okr} / ${cards.total_employees}`" />
        </div>

        <!-- Grid inferior 67/33 -->
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(280px,1fr)]">
            <!-- Izquierda: Objetivos y Key Results -->
            <div class="app-card p-4">
                <p class="mb-3 text-sm font-bold text-foreground">Objetivos y Key Results</p>
                <div v-if="sortedObjectives.length" class="overflow-x-auto">
                    <table class="w-full min-w-[720px] text-sm">
                        <thead class="border-b border-border text-xs text-muted-foreground">
                            <tr>
                                <th class="px-2 py-2 text-left font-semibold">Objetivo</th>
                                <th class="px-2 py-2 text-left font-semibold">Key Results</th>
                                <th class="px-2 py-2 text-left font-semibold">KPIs relacionados</th>
                                <th class="px-2 py-2 text-left font-semibold">Plazo</th>
                                <th class="px-2 py-2 text-left font-semibold">Avance</th>
                                <th class="px-2 py-2 text-left font-semibold">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in sortedObjectives" :key="o.id" class="border-b border-border/60 align-top last:border-0">
                                <td class="max-w-[220px] px-2 py-3">
                                    <Link :href="`/okr/${o.id}`" class="line-clamp-2 text-sm font-semibold text-foreground hover:text-primary hover:underline">{{ o.title }}</Link>
                                    <p class="mt-0.5 text-xs text-muted-foreground">{{ o.branch ?? o.employee ?? '—' }}</p>
                                </td>
                                <td class="max-w-[200px] px-2 py-3">
                                    <ul class="space-y-0.5 text-xs text-muted-foreground">
                                        <li v-for="(kr, i) in o.key_results.slice(0, 3)" :key="i" class="truncate">• {{ kr }}</li>
                                    </ul>
                                </td>
                                <td class="max-w-[160px] px-2 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="kpi in o.kpis" :key="kpi" class="rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-semibold text-primary">{{ kpi }}</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-2 py-3 text-xs text-muted-foreground">{{ formatFriendlyDate(o.end_date) }}</td>
                                <td class="px-2 py-3">
                                    <div class="flex items-center gap-2">
                                        <OkrProgressBar :value="o.compliance" />
                                        <span class="shrink-0 text-xs font-semibold tabular-nums text-muted-foreground">{{ o.compliance }}%</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-2 py-3"><OkrStatusBadge kind="health" :value="o.health_status" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <AppEmptyState
                    v-else-if="!hasAnyObjective"
                    title="No hay OKR todavía"
                    message="Comienza creando el primer objetivo para una sucursal o colaborador."
                >
                    <Button class="h-11 gap-2 rounded-xl" @click="assignOpen = true"><UserPlus class="size-4" /> Asignar OKR</Button>
                </AppEmptyState>
                <AppEmptyState v-else title="No encontramos OKR con esos filtros" message="Ajusta o limpia los filtros para ver más resultados.">
                    <Button variant="outline" class="h-11 rounded-xl" @click="clearFilters">Limpiar filtros</Button>
                </AppEmptyState>
            </div>

            <!-- Derecha: Cumplimiento general + Riesgo y proyección -->
            <div class="space-y-4">
                <OkrComplianceDonut :breakdown="compliance_breakdown" :avg-compliance="cards.avg_compliance" />
                <OkrRiskProjectionPanel :rows="risk_projection" />
            </div>
        </div>

        <OkrAssignDialog
            v-model:open="assignOpen"
            :branches="wizardBranches"
            :kpis="wizardKpis"
            :current-user="wizardCurrentUser"
            :users="wizardUsers"
        />
    </div>
</template>
