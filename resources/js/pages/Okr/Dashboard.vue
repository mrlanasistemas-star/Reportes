<script setup lang="ts">
// Módulo OKR — Dashboard (rediseño 08-sep-2026, secciones L-N/AG del pedido).
// Ancho completo del contenedor (nunca max-w-7xl con espacio muerto lateral).
import { computed, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { AlertTriangle, Building2, Gauge, History, Plus, Target, Users, XCircle } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import { Button } from '@/components/ui/button'
import OkrFilters from '@/components/okr/OkrFilters.vue'
import OkrStatCard from '@/components/okr/OkrStatCard.vue'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import OkrProgressBar from '@/components/okr/OkrProgressBar.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    objectives: any[]
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

const sortedObjectives = computed(() => [...props.objectives].sort((a, b) => (a.health_status === 'off_track' ? -1 : 1)))
const hasAnyObjective = computed(() => props.cards.active > 0 || props.objectives.length > 0)
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

        <!-- Cards ejecutivas -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
            <OkrStatCard :icon="Target" label="OKR activos" :value="cards.active" hint="Objetivos vigentes" tone="primary" />
            <OkrStatCard :icon="AlertTriangle" label="OKR en riesgo" :value="cards.risk" hint="Requieren atención" tone="warning" />
            <OkrStatCard :icon="XCircle" label="No cumplidos" :value="cards.not_met" hint="Cerrados sin alcanzar la meta" tone="danger" />
            <OkrStatCard :icon="Gauge" label="Cumplimiento promedio" :value="`${cards.avg_compliance}%`" hint="Alcance actual" tone="success" />
            <OkrStatCard :icon="Building2" label="Sucursales con OKR" :value="`${cards.branches_with_okr}/${cards.total_branches}`" hint="Del alcance filtrado" />
            <OkrStatCard :icon="Users" label="Colaboradores con OKR" :value="`${cards.employees_with_okr}/${cards.total_employees}`" hint="Total de colaboradores activos" global />
        </div>

        <!-- Listado -->
        <div class="app-table-wrap">
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
