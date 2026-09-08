<script setup lang="ts">
// Módulo OKR — "Seguimiento OKR". Reconstruido 10-sep-2026 para reproducir
// 1:1 docs/imagenesOKR/9-12.png (auditoría de esa fecha, secciones 22-28):
// hero + UNA barra de filtros + 7 cards en una fila + grid 64/36 (Avance por
// KPI | Evidencias + Check-in). Sin tabs, sin command-center, sin progress
// ring gigante, sin Attention Center — esas ideas del rediseño anterior NO
// están en la referencia.
import { computed, onMounted, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import {
    ArrowDown, ArrowLeft, ArrowUp, Bell, CalendarClock, Flag, Gauge,
    History, Target, TrendingDown, TrendingUp, Upload, X,
} from 'lucide-vue-next'
import Swal from 'sweetalert2'
import AppLayout from '@/layouts/AppLayout.vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import { Button } from '@/components/ui/button'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import SelectField from '@/components/forms/SelectField.vue'
import OkrStatCard from '@/components/okr/OkrStatCard.vue'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import OkrProgressBar from '@/components/okr/OkrProgressBar.vue'
import OkrEditGoalDialog from '@/components/okr/OkrEditGoalDialog.vue'
import OkrWeightsDialog from '@/components/okr/OkrWeightsDialog.vue'
import OkrCheckInDialog from '@/components/okr/OkrCheckInDialog.vue'
import OkrEvidenceUploadDialog from '@/components/okr/OkrEvidenceUploadDialog.vue'
import { formatByUnit, formatFriendlyDate, formatPp } from '@/lib/okrFormat'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    objective: any
    keyResults: any[]
    checkIns: any[]
    correctiveActions: any[]
    evidences: any[]
    alerts: any[]
    auditLogs: any[]
    canManage: boolean
    canAssign: boolean
    canUpdate: boolean
    canCheckin: boolean
    canUploadEvidence: boolean
    filterBranches: { id: number; name: string }[]
    filterPeriods: { id: number; label: string }[]
    users: { id: number; name: string }[]
}>()

const statusLabel: Record<string, string> = { draft: 'Borrador', active: 'Activo', closed: 'Cerrado', cancelled: 'Cancelado' }
function fmt(v: number | null, unit?: string) { return formatByUnit(v, unit) }

function activate() { router.post(`/okr/${props.objective.id}/activate`) }
function refresh() { router.post(`/okr/${props.objective.id}/refresh`) }
function destroyObjective() {
    Swal.fire({
        icon: 'warning', title: '¿Eliminar este OKR?', text: 'Se cancelará y quedará fuera del tablero activo. El histórico se conserva.',
        showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#e11d48',
    }).then((r) => { if (r.isConfirmed) router.delete(`/okr/${props.objective.id}`) })
}

// ── Filtros de Seguimiento (docs/imagenesOKR/9.png) — cambiar de OKR sin volver al Dashboard. ──
const filterBranchId = ref<number | null>(props.objective.branch?.id ?? null)
const filterEmployeeId = ref<number | null>(props.objective.employee?.id ?? null)
const filterPeriodId = ref<string>('')
const filterSearch = ref('')
const selectedObjectiveId = ref<number>(props.objective.id)
const employeeOptions = ref<{ id: number; full_name: string }[]>([])
const objectiveOptions = ref<{ id: number; title: string }[]>([{ id: props.objective.id, title: props.objective.title }])

async function loadEmployees() {
    const params = new URLSearchParams()
    if (filterBranchId.value) params.set('branch_id', String(filterBranchId.value))
    const res = await fetch(`/okr/employees-lookup?${params.toString()}`, { headers: { Accept: 'application/json' } })
    employeeOptions.value = res.ok ? (await res.json()).employees ?? [] : []
}
async function loadObjectives() {
    const params = new URLSearchParams()
    if (filterBranchId.value) params.set('branch_id', String(filterBranchId.value))
    if (filterEmployeeId.value) params.set('employee_id', String(filterEmployeeId.value))
    if (filterPeriodId.value) params.set('period_id', filterPeriodId.value)
    if (filterSearch.value) params.set('search', filterSearch.value)
    const res = await fetch(`/okr/objectives-lookup?${params.toString()}`, { headers: { Accept: 'application/json' } })
    objectiveOptions.value = res.ok ? (await res.json()).objectives ?? [] : []
}
onMounted(loadEmployees)
watch(filterBranchId, () => { filterEmployeeId.value = null; loadEmployees(); loadObjectives() })
watch([filterEmployeeId, filterPeriodId], loadObjectives)
function onObjectiveSearch(term: string) { filterSearch.value = term; loadObjectives() }
function goToObjective(id: number | string | null) { if (id && id !== props.objective.id) router.get(`/okr/${id}`) }
function clearTrackingFilters() {
    filterBranchId.value = null; filterEmployeeId.value = null; filterPeriodId.value = ''; filterSearch.value = ''
    loadEmployees(); loadObjectives()
}

const kpiSearch = ref('')
const filteredKeyResults = computed(() => {
    if (!kpiSearch.value.trim()) return props.keyResults
    const term = kpiSearch.value.toLowerCase()
    return props.keyResults.filter((kr) => kr.kpi.name.toLowerCase().includes(term) || kr.description.toLowerCase().includes(term))
})

const weeksLeft = computed(() => Math.max(0, (props.objective.duration_weeks ?? 0) - (props.objective.current_week ?? 0)))

// ── Editar meta / redistribuir pesos ──
const editGoalOpen = ref(false)
const editingKr = ref<any | null>(null)
function openEditGoal(kr: any) { editingKr.value = kr; editGoalOpen.value = true }
const weightsOpen = ref(false)

// ── Check-in / Evidencia (Dialogs) ──
const checkInOpen = ref(false)
const evidenceOpen = ref(false)
const currentWeek = props.objective.current_week
const alreadyCheckedInThisWeek = computed(() => props.checkIns.some((c) => c.week_number === currentWeek))

function trendIcon(kr: any) {
    return (kr.deviation_pp ?? 0) >= 0 ? TrendingUp : TrendingDown
}
</script>

<template>
    <div class="w-full space-y-4 px-4 py-4 sm:px-6 sm:py-6 lg:px-8">
        <div class="flex items-center gap-2">
            <Link href="/okr" class="text-muted-foreground transition hover:text-foreground"><ArrowLeft class="size-4" /></Link>
            <span class="text-xs text-muted-foreground">Volver al dashboard</span>
        </div>

        <!-- Hero -->
        <section class="overflow-hidden rounded-2xl bg-slate-950 px-5 py-5 text-white shadow-md sm:px-7">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <p class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.2em] text-indigo-300"><Target class="size-3.5" /> Sistema Reportes</p>
                    <h1 class="mt-1 text-2xl font-bold tracking-tight">Seguimiento OKR</h1>
                    <p class="mt-1 text-sm text-slate-300">Controla el avance real vs esperado, evidencia y acciones correctivas.</p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <Button v-if="canUploadEvidence" class="h-10 gap-2 rounded-xl bg-emerald-500 text-white hover:bg-emerald-400" @click="evidenceOpen = true">
                        <Upload class="size-4" /> Cargar evidencia
                    </Button>
                    <Link href="/okr/history">
                        <Button variant="outline" class="h-10 gap-2 rounded-xl border-white/20 bg-white/5 text-white hover:bg-white/10"><History class="size-4" /> Histórico</Button>
                    </Link>
                </div>
            </div>
        </section>

        <!-- Acciones de ciclo de vida (activar/recalcular/redistribuir/eliminar) -->
        <div v-if="(objective.lifecycle_status === 'draft' && canAssign) || (objective.lifecycle_status === 'active' && canUpdate) || canManage" class="flex flex-wrap items-center gap-2">
            <Button v-if="objective.lifecycle_status === 'draft' && canAssign" size="sm" class="h-9 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500" @click="activate">Activar OKR</Button>
            <Button v-if="objective.lifecycle_status === 'active' && canUpdate" size="sm" variant="outline" class="h-9 rounded-xl" @click="refresh">Recalcular</Button>
            <Button v-if="objective.lifecycle_status === 'active' && canUpdate" size="sm" variant="outline" class="h-9 rounded-xl" @click="weightsOpen = true">Redistribuir ponderaciones</Button>
            <Button v-if="canManage && objective.lifecycle_status !== 'cancelled'" size="sm" variant="outline" class="h-9 rounded-xl border-destructive/30 text-destructive hover:bg-destructive/10" @click="destroyObjective">Eliminar</Button>
        </div>

        <p v-if="objective.lifecycle_status === 'draft'" class="rounded-xl border border-primary/20 bg-primary/5 px-4 py-2.5 text-xs font-semibold text-primary">
            OKR en borrador — ponderación {{ objective.weight_summary.total }}% ({{ objective.weight_summary.is_valid ? 'lista para activar' : 'incompleta' }}). Al activar, la línea base queda congelada.
        </p>
        <div v-if="alerts.length" class="space-y-1.5">
            <div v-for="a in alerts" :key="a.id" class="flex items-center gap-2 rounded-xl border border-amber-500/20 bg-amber-500/5 px-3 py-2 text-xs font-semibold text-amber-700 dark:text-amber-300">
                <Bell class="size-3.5 shrink-0" /> {{ a.message }}
            </div>
        </div>

        <!-- Filtros -->
        <div class="app-card p-3">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                <div class="grid flex-1 grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                    <SelectField v-model="filterBranchId as any" label="Sucursal" placeholder="Todas" :options="[{ value: '', label: 'Todas' }, ...filterBranches.map((b) => ({ value: b.id, label: b.name }))]" />
                    <SearchableSelect v-model="filterEmployeeId as any" label="Colaborador" placeholder="Todos" :options="employeeOptions" label-key="full_name" secondary-key="__none" allow-null null-label="Todos" />
                    <SearchableSelect v-model="selectedObjectiveId as any" label="Objective / OKR" :options="objectiveOptions" label-key="title" secondary-key="__none" @update:search="onObjectiveSearch" @change="goToObjective" />
                    <SelectField v-model="filterPeriodId" label="Periodo" placeholder="Todos" :options="[{ value: '', label: 'Todos' }, ...filterPeriods.map((p) => ({ value: String(p.id), label: p.label }))]" />
                    <div class="space-y-1.5 sm:col-span-3 lg:col-span-1">
                        <label class="text-sm font-semibold text-foreground">Buscar</label>
                        <input v-model="kpiSearch" placeholder="Buscar por KPI, archivo o nota..." class="app-input">
                    </div>
                </div>
                <Button type="button" variant="ghost" size="sm" class="h-11 shrink-0 gap-1.5 rounded-xl text-muted-foreground" @click="clearTrackingFilters(); kpiSearch = ''">
                    <X class="size-3.5" /> Limpiar filtros
                </Button>
            </div>
        </div>

        <!-- 7 cards -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
            <OkrStatCard :icon="Target" label="Objective / OKR" :value="objective.branch?.name ?? objective.employee?.full_name ?? '—'" :hint="objective.title.slice(0, 28) + '…'" tone="primary" />
            <OkrStatCard :icon="CalendarClock" label="Plazo" :value="`${objective.duration_weeks} semanas`" :hint="`${formatFriendlyDate(objective.start_date)} - ${formatFriendlyDate(objective.end_date)}`" />
            <OkrStatCard :icon="TrendingUp" label="Avance esperado" :value="`${objective.expected_compliance}%`" hint="Esperado a la fecha" />
            <OkrStatCard :icon="Gauge" label="Avance real" :value="`${objective.compliance}%`" hint="Real a la fecha" tone="primary" />
            <OkrStatCard :icon="objective.deviation_pp >= 0 ? ArrowUp : ArrowDown" label="Desviación" :value="formatPp(objective.deviation_pp)" hint="vs esperado" :tone="objective.deviation_pp >= 0 ? 'success' : 'danger'" />
            <OkrStatCard :icon="Target" label="Proyección de cierre" :value="objective.projected_compliance === null ? '—' : `${objective.projected_compliance}%`" hint="Al final del periodo" />
            <OkrStatCard :icon="Flag" label="Estado" :value="statusLabel[objective.lifecycle_status]" :hint="objective.health_status ?? undefined" tone="warning" />
        </div>

        <!-- Grid 64/36 -->
        <div class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,16fr)_minmax(280px,9fr)]">
            <!-- Avance por KPI -->
            <div class="app-card p-4">
                <p class="mb-3 text-sm font-bold text-foreground">Avance por KPI</p>
                <div v-if="filteredKeyResults.length" class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-xs">
                        <thead class="border-b border-border text-muted-foreground">
                            <tr>
                                <th class="px-2 py-2 text-left font-semibold">KPI</th>
                                <th class="px-2 py-2 text-left font-semibold">Base</th>
                                <th class="px-2 py-2 text-left font-semibold">Meta</th>
                                <th class="px-2 py-2 text-left font-semibold">Actual</th>
                                <th class="px-2 py-2 text-left font-semibold">Esperado a la fecha</th>
                                <th class="px-2 py-2 text-left font-semibold">Desviación</th>
                                <th class="px-2 py-2 text-left font-semibold">Cumplimiento</th>
                                <th class="px-2 py-2 text-left font-semibold">Tendencia</th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="kr in filteredKeyResults" :key="kr.id" class="border-t border-border">
                                <td class="max-w-[180px] px-2 py-2.5">
                                    <p class="truncate font-semibold text-foreground">{{ kr.kpi.name }}</p>
                                    <p class="truncate text-[11px] text-muted-foreground">{{ kr.description }}</p>
                                </td>
                                <td class="whitespace-nowrap px-2 py-2.5 tabular-nums">{{ fmt(kr.baseline_value, kr.kpi.unit) }}</td>
                                <td class="whitespace-nowrap px-2 py-2.5 tabular-nums">{{ fmt(kr.target_value, kr.kpi.unit) }}</td>
                                <td class="whitespace-nowrap px-2 py-2.5 font-semibold tabular-nums">{{ fmt(kr.current_value, kr.kpi.unit) }}</td>
                                <td class="whitespace-nowrap px-2 py-2.5 tabular-nums">{{ fmt(kr.expected_value, kr.kpi.unit) }}</td>
                                <td class="whitespace-nowrap px-2 py-2.5 font-semibold tabular-nums" :class="(kr.deviation_pp ?? 0) < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">{{ formatPp(kr.deviation_pp) }}</td>
                                <td class="px-2 py-2.5">
                                    <div class="flex items-center gap-1.5">
                                        <OkrProgressBar v-if="kr.actual_progress_percentage !== null" :value="kr.actual_progress_percentage" />
                                        <span class="shrink-0 text-[11px] font-semibold tabular-nums">{{ kr.actual_progress_percentage === null ? '—' : kr.actual_progress_percentage + '%' }}</span>
                                    </div>
                                </td>
                                <td class="px-2 py-2.5">
                                    <component :is="trendIcon(kr)" class="size-4" :class="(kr.deviation_pp ?? 0) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'" />
                                </td>
                                <td class="px-2 py-2.5">
                                    <button v-if="objective.lifecycle_status === 'active' && canUpdate" class="text-[11px] font-bold text-primary hover:underline" @click="openEditGoal(kr)">Editar</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <AppEmptyState v-else title="Sin Key Results" message="Este OKR todavía no tiene resultados clave configurados." />

                <!-- Contribución sucursal ↔ gestores — solo KPI distribuibles, solo si hay individuales -->
                <div v-for="c in objective.contributions ?? []" :key="c.kpi.id" class="mt-4 border-t border-border pt-4">
                    <p class="mb-2 text-sm font-bold text-foreground">Contribución a la meta — {{ c.kpi.name }}</p>
                    <div class="mb-2 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                        <div><p class="text-muted-foreground">Meta sucursal</p><p class="font-semibold tabular-nums text-foreground">{{ fmt(c.branch_target, c.kpi.unit) }}</p></div>
                        <div><p class="text-muted-foreground">Suma gestores</p><p class="font-semibold tabular-nums text-foreground">{{ fmt(c.children_current_sum, c.kpi.unit) }}</p></div>
                        <div><p class="text-muted-foreground">Cobertura</p><p class="font-semibold tabular-nums text-primary">{{ c.coverage_percentage === null ? '—' : c.coverage_percentage + '%' }}</p></div>
                        <div><p class="text-muted-foreground">Faltante</p><p class="font-semibold tabular-nums" :class="c.gap > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">{{ fmt(Math.abs(c.gap), c.kpi.unit) }}</p></div>
                    </div>
                    <OkrProgressBar :value="c.coverage_percentage ?? 0" />
                    <div class="mt-2 space-y-1">
                        <div v-for="row in c.rows" :key="row.employee" class="flex items-center justify-between text-xs">
                            <span class="font-medium text-foreground">{{ row.employee }}</span>
                            <span class="text-muted-foreground">{{ fmt(row.current_value, c.kpi.unit) }} / {{ fmt(row.target_value, c.kpi.unit) }} <span class="ml-1 font-semibold text-foreground">({{ row.compliance ?? '—' }}%)</span></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Evidencias + Check-in -->
            <div class="space-y-4">
                <div class="app-card p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-sm font-bold text-foreground">Evidencias y archivos de seguimiento</p>
                        <Button v-if="canUploadEvidence" size="sm" variant="outline" class="h-8 gap-1.5 rounded-lg text-xs" @click="evidenceOpen = true"><Upload class="size-3" /> Subir archivo</Button>
                    </div>
                    <div v-if="evidences.length" class="space-y-2">
                        <a v-for="e in evidences" :key="e.id" :href="`/okr/evidences/${e.id}/download`" class="flex items-center gap-2 rounded-lg border border-border p-2 text-xs transition hover:bg-muted/40">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold text-foreground">{{ e.original_name }}</span>
                                <span class="block text-[11px] text-muted-foreground">{{ e.uploader }} · {{ formatFriendlyDate(e.created_at?.slice(0, 10)) }}</span>
                            </span>
                            <span class="shrink-0 rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300">Cargado</span>
                        </a>
                    </div>
                    <p v-else class="py-4 text-center text-xs text-muted-foreground">Sin evidencias todavía.</p>
                </div>

                <div class="app-card p-4">
                    <div class="mb-3 flex items-center justify-between">
                        <p class="text-sm font-bold text-foreground">Check-in semanal</p>
                        <Button v-if="canCheckin" size="sm" variant="outline" class="h-8 rounded-lg text-xs" @click="checkInOpen = true">Registrar check-in</Button>
                    </div>
                    <div v-if="checkIns.length" class="space-y-2">
                        <div v-for="c in checkIns.slice(0, 3)" :key="c.id" class="rounded-lg border border-border p-2 text-xs">
                            <p class="font-semibold text-foreground">Semana {{ c.week_number }} — {{ c.user }}</p>
                            <p v-if="c.main_blocker" class="text-muted-foreground">Bloqueo: {{ c.main_blocker }}</p>
                            <p v-if="c.corrective_action" class="text-muted-foreground">Acción: {{ c.corrective_action }}</p>
                        </div>
                    </div>
                    <p v-else class="py-4 text-center text-xs text-muted-foreground">Sin check-ins todavía.</p>
                </div>

                <div v-if="correctiveActions.length" class="app-card p-4">
                    <p class="mb-2 text-sm font-bold text-foreground">Acciones correctivas</p>
                    <div class="space-y-2">
                        <div v-for="a in correctiveActions" :key="a.id" class="flex items-center justify-between rounded-lg border border-border p-2 text-xs">
                            <div class="min-w-0"><p class="truncate font-semibold text-foreground">{{ a.description }}</p><p class="text-muted-foreground">{{ a.responsible }} — vence {{ formatFriendlyDate(a.due_date) }}</p></div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 font-semibold" :class="a.is_overdue ? 'bg-destructive/10 text-destructive' : 'bg-muted text-muted-foreground'">{{ a.is_overdue ? 'Vencida' : a.status }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Historial del OKR (bitácora — requisito PDF sección 24, no visible en el recorte de las imágenes) -->
        <details v-if="auditLogs.length" class="app-card p-4">
            <summary class="cursor-pointer text-sm font-bold text-foreground">Historial del OKR ({{ auditLogs.length }})</summary>
            <div class="mt-3 space-y-2">
                <div v-for="log in auditLogs" :key="log.id" class="border-l-2 border-border pl-3 text-xs">
                    <p class="font-semibold text-foreground">{{ log.field === 'weight' ? 'Ponderación' : log.field === 'target_value' ? 'Meta' : log.field ?? log.action }} modificada <span v-if="log.old_value !== null" class="font-normal text-muted-foreground">— {{ log.old_value }} → {{ log.new_value }}</span></p>
                    <p class="text-muted-foreground">{{ log.user ?? 'Sistema' }} · {{ log.created_at }} <span v-if="log.reason">· "{{ log.reason }}"</span></p>
                </div>
            </div>
        </details>

        <OkrEditGoalDialog v-model:open="editGoalOpen" :objective-id="objective.id" :key-result="editingKr" />
        <OkrWeightsDialog v-model:open="weightsOpen" :objective-id="objective.id" :key-results="keyResults" />
        <OkrCheckInDialog v-model:open="checkInOpen" :objective-id="objective.id" :key-results="keyResults" :users="users" :already-checked-in-this-week="alreadyCheckedInThisWeek" />
        <OkrEvidenceUploadDialog v-model:open="evidenceOpen" :objective-id="objective.id" />
    </div>
</template>
