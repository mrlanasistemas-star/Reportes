<script setup lang="ts">
// Módulo OKR — Detalle de Objective ("command center", secciones 18-27 de la
// auditoría 09-sep-2026). Hero siempre visible + Tabs (nunca una sola página
// larguísima) con deep link ?tab=. Editar meta y redistribuir ponderaciones
// ahora en Dialog (nunca un form suelto debajo de la tabla). El check-in
// captura resultados manuales reales (bug corregido, punto 1).
import { computed, reactive, ref, watch } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import {
    Activity, ArrowLeft, Bell, Building2, CalendarClock, CalendarDays, CheckCircle2, ClipboardList,
    Compass, FileText, Flag, Gauge, History, Paperclip, RefreshCw, Scale, Trash2, Upload, UserRound, Users,
} from 'lucide-vue-next'
import Swal from 'sweetalert2'
import AppLayout from '@/layouts/AppLayout.vue'
import ChartCard from '@/components/radiography/ChartCard.vue'
import TextareaField from '@/components/forms/TextareaField.vue'
import DatePickerField from '@/components/forms/DatePickerField.vue'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import { Button } from '@/components/ui/button'
import OkrStatCard from '@/components/okr/OkrStatCard.vue'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import OkrProgressBar from '@/components/okr/OkrProgressBar.vue'
import OkrProgressRing from '@/components/okr/OkrProgressRing.vue'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
import OkrWhyPopover from '@/components/okr/OkrWhyPopover.vue'
import OkrEditGoalDialog from '@/components/okr/OkrEditGoalDialog.vue'
import OkrWeightsDialog from '@/components/okr/OkrWeightsDialog.vue'
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
}>()

const statusLabel: Record<string, string> = { draft: 'Borrador', active: 'Activo', closed: 'Cerrado', cancelled: 'Cancelado' }
const qualityLabel: Record<string, string> = {
    exact: 'Exacto', monthly_proxy: 'Fuente mensual', last_available: 'Último cierre disponible', manual_checkin: 'Captura manual',
}

function fmt(v: number | null, unit?: string) {
    return formatByUnit(v, unit)
}

function activate() { router.post(`/okr/${props.objective.id}/activate`) }
function refresh() { router.post(`/okr/${props.objective.id}/refresh`) }

function destroyObjective() {
    Swal.fire({
        icon: 'warning', title: '¿Eliminar este OKR?',
        text: 'Se cancelará y quedará fuera del tablero activo. El histórico se conserva.',
        showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar',
        confirmButtonColor: '#e11d48',
    }).then((r) => { if (r.isConfirmed) router.delete(`/okr/${props.objective.id}`) })
}

// ── Tabs con deep link ?tab= (sección 19) — sin ida y vuelta al servidor. ──
const TABS = [
    { key: 'resumen', label: 'Resumen', icon: Activity },
    { key: 'keyresults', label: 'Key Results', icon: ClipboardList },
    { key: 'seguimiento', label: 'Seguimiento', icon: Gauge },
    { key: 'checkins', label: 'Check-ins', icon: CheckCircle2 },
    { key: 'evidencias', label: 'Evidencias', icon: Paperclip },
    { key: 'bitacora', label: 'Bitácora', icon: History },
]
const activeTab = ref<string>(new URLSearchParams(window.location.search).get('tab') || 'resumen')
watch(activeTab, (tab) => {
    const url = new URL(window.location.href)
    url.searchParams.set('tab', tab)
    window.history.replaceState({}, '', url)
})

const weeksLeft = computed(() => Math.max(0, (props.objective.duration_weeks ?? 0) - (props.objective.current_week ?? 0)))

// ── Gráfica esperado vs real / cumplimiento % (por KR seleccionado) — sección 21 ──
const selectedKrId = ref(props.keyResults[0]?.id ?? null)
const selectedKr = computed(() => props.keyResults.find((k) => k.id === selectedKrId.value))
const chartMode = ref<'value' | 'compliance'>('value')

const chartSeries = computed(() => {
    const kr = selectedKr.value
    if (!kr) return []
    if (chartMode.value === 'compliance') {
        return [
            { name: 'Esperado %', data: kr.snapshots.map((s: any) => s.expected_progress_percentage) },
            { name: 'Cumplimiento %', data: kr.snapshots.map((s: any) => s.actual_progress_percentage) },
        ]
    }
    return [
        { name: 'Esperado', data: kr.snapshots.map((s: any) => s.expected_value) },
        { name: 'Real', data: kr.snapshots.map((s: any) => s.actual_value) },
    ]
})
const chartOptions = computed(() => {
    const kr = selectedKr.value
    const snapshots = kr?.snapshots ?? []
    const todayLabel = `Semana ${props.objective.current_week}`

    return {
        chart: { toolbar: { show: false } },
        xaxis: { categories: snapshots.map((s: any) => `Semana ${s.week_number}`) },
        colors: ['#94a3b8', '#4f46e5'],
        stroke: { curve: 'smooth', width: 3 },
        legend: { position: 'top' },
        annotations: {
            xaxis: [{
                x: todayLabel, borderColor: '#f59e0b', strokeDashArray: 4,
                label: { text: 'Hoy', style: { color: '#fff', background: '#f59e0b' } },
            }],
        },
        tooltip: {
            y: { formatter: (v: number) => chartMode.value === 'compliance' ? `${v}%` : fmt(v, kr?.kpi?.unit) },
            x: {
                formatter: (_v: number, opts: any) => {
                    const snap = snapshots[opts.dataPointIndex]
                    const source = snap?.source_quality ? qualityLabel[snap.source_quality] ?? snap.source_quality : null
                    return `Semana ${snap?.week_number ?? ''}${source ? ` · ${source}` : ''}`
                },
            },
        },
    }
})

// ── Check-in + resultados manuales (bug corregido, punto 1 de la auditoría) ──
const checkInForm = useForm({
    main_blocker: '', corrective_action: '', action_responsible_user_id: null as number | null, action_due_date: null as string | null,
    manual_results: [] as { key_result_id: number; value: number }[],
})
const manualKeyResults = computed(() => props.keyResults.filter((kr) => kr.kpi.automation !== 'automatic'))
const manualValues = reactive<Record<number, string>>({})
watch(manualKeyResults, (list) => {
    for (const kr of list) {
        if (!(kr.id in manualValues)) manualValues[kr.id] = ''
    }
}, { immediate: true })

function submitCheckIn() {
    checkInForm.manual_results = manualKeyResults.value
        .filter((kr) => manualValues[kr.id] !== '')
        .map((kr) => ({ key_result_id: kr.id, value: Number(manualValues[kr.id]) }))

    checkInForm.post(`/okr/${props.objective.id}/check-ins`, {
        onSuccess: () => {
            checkInForm.reset()
            for (const kr of manualKeyResults.value) manualValues[kr.id] = ''
            Swal.fire({ icon: 'success', title: 'Check-in guardado', confirmButtonColor: '#4f46e5', timer: 1800, showConfirmButton: false })
        },
    })
}

// ── Evidencia (dropzone) ──
const evidenceForm = useForm({ file: null as File | null, comment: '', okr_key_result_id: null as number | null })
const isDragging = ref(false)
function pickFile(file: File | undefined | null) { evidenceForm.file = file ?? null }
function onFileChange(e: Event) { pickFile((e.target as HTMLInputElement).files?.[0]) }
function onDrop(e: DragEvent) { isDragging.value = false; pickFile(e.dataTransfer?.files?.[0]) }
function submitEvidence() {
    evidenceForm.post(`/okr/${props.objective.id}/evidences`, {
        onSuccess: () => { evidenceForm.reset(); Swal.fire({ icon: 'success', title: 'Evidencia subida', confirmButtonColor: '#4f46e5', timer: 1800, showConfirmButton: false }) },
    })
}
function formatSize(bytes?: number) {
    if (!bytes) return ''
    const kb = bytes / 1024
    return kb < 1024 ? `${kb.toFixed(0)} KB` : `${(kb / 1024).toFixed(1)} MB`
}

// ── Editar meta / redistribuir pesos (Dialogs — sección 20) ──
const editGoalOpen = ref(false)
const editingKr = ref<any | null>(null)
function openEditGoal(kr: any) { editingKr.value = kr; editGoalOpen.value = true }
const weightsOpen = ref(false)

// ── Actividad reciente (sección 27) — fusiona check-ins/evidencias/bitácora ──
const activityFeed = computed(() => {
    const items: { at: string; text: string }[] = []
    for (const c of props.checkIns) items.push({ at: c.check_in_date, text: `Check-in realizado por ${c.user}` })
    for (const e of props.evidences) items.push({ at: e.created_at, text: `Evidencia subida por ${e.uploader}: ${e.original_name}` })
    for (const a of props.correctiveActions) items.push({ at: a.due_date, text: `Acción correctiva creada — responsable: ${a.responsible}` })
    for (const log of props.auditLogs) {
        items.push({ at: log.created_at, text: `${log.field === 'weight' ? 'Peso' : log.field === 'target_value' ? 'Meta' : log.field ?? log.action} modificada por ${log.user ?? 'Sistema'}` })
    }
    return items.sort((a, b) => b.at.localeCompare(a.at)).slice(0, 8)
})

const fieldLabel: Record<string, string> = { target_value: 'Meta', weight: 'Ponderación' }
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 pb-24 sm:px-6 lg:px-8 lg:pb-6">
        <div class="flex items-center gap-3">
            <Link href="/okr" class="text-muted-foreground transition hover:text-foreground"><ArrowLeft class="size-5" /></Link>
            <p class="text-sm text-muted-foreground">Detalle del OKR</p>
        </div>

        <!-- Hero -->
        <section class="app-card p-6">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <OkrStatusBadge kind="lifecycle" :value="objective.lifecycle_status" />
                        <OkrWhyPopover
                            v-if="objective.health_status"
                            :health="objective.health_status"
                            :expected-progress="null" :actual-progress="objective.compliance"
                            :deviation-pp="null" :weeks-left="weeksLeft" :projected-compliance="null"
                        />
                        <OkrStatusBadge v-if="objective.final_status" kind="final" :value="objective.final_status" />
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ objective.title }}</h1>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                        <span class="inline-flex items-center gap-1.5"><Building2 v-if="objective.branch" class="size-3.5" /><Users v-else class="size-3.5" /> {{ objective.branch?.name ?? objective.employee?.full_name ?? '—' }}</span>
                        <span class="inline-flex items-center gap-1.5"><UserRound class="size-3.5" /> Responsable: {{ objective.responsible?.name ?? '—' }}</span>
                        <span class="inline-flex items-center gap-1.5"><CalendarDays class="size-3.5" /> {{ formatFriendlyDate(objective.start_date) }} → {{ formatFriendlyDate(objective.end_date) }}</span>
                    </div>
                    <!-- Acciones (desktop) -->
                    <div class="hidden flex-wrap gap-2 pt-2 lg:flex">
                        <Button v-if="objective.lifecycle_status === 'draft' && canAssign" class="h-10 gap-2 rounded-2xl bg-emerald-600 text-white hover:bg-emerald-500" @click="activate">
                            <CheckCircle2 class="size-4" /> Activar OKR
                        </Button>
                        <Button v-if="objective.lifecycle_status === 'active' && canUpdate" variant="outline" class="h-10 gap-2 rounded-2xl" @click="refresh">
                            <RefreshCw class="size-4" /> Recalcular
                        </Button>
                        <Button v-if="objective.lifecycle_status === 'active' && canUpdate" variant="outline" class="h-10 gap-2 rounded-2xl" @click="weightsOpen = true">
                            <Scale class="size-4" /> Redistribuir ponderaciones
                        </Button>
                        <Button v-if="canManage && objective.lifecycle_status !== 'cancelled'" variant="outline" class="h-10 gap-2 rounded-2xl border-destructive/30 text-destructive hover:bg-destructive/10" @click="destroyObjective">
                            <Trash2 class="size-4" /> Eliminar
                        </Button>
                    </div>
                </div>

                <div class="flex shrink-0 items-center justify-center">
                    <OkrProgressRing :value="objective.compliance" label="Cumplimiento general" :size="128" />
                </div>
            </div>

            <p v-if="objective.lifecycle_status === 'draft'" class="mt-4 flex items-start gap-2 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-xs font-semibold text-primary">
                <Compass class="mt-0.5 size-4 shrink-0" />
                OKR en borrador — revisa la línea base y ponderación antes de activar. Al activar, la línea base queda CONGELADA.
                Ponderación actual: {{ objective.weight_summary.total }}%
                ({{ objective.weight_summary.is_valid ? 'lista para activar' : (objective.weight_summary.remaining > 0 ? objective.weight_summary.remaining + '% pendiente' : Math.abs(objective.weight_summary.remaining) + '% excedido') }}).
            </p>
        </section>

        <!-- Alertas -->
        <div v-if="alerts.length" class="space-y-1.5">
            <div v-for="a in alerts" :key="a.id" class="flex items-center gap-2 rounded-xl border border-amber-500/20 bg-amber-500/5 px-3 py-2.5 text-xs font-semibold text-amber-700 dark:text-amber-300">
                <Bell class="size-3.5 shrink-0" /> {{ a.message }}
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex items-center gap-1 overflow-x-auto rounded-2xl border border-border bg-card p-1 scrollbar-none">
            <button
                v-for="tab in TABS" :key="tab.key" type="button"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-xl px-3 py-2 text-xs font-semibold transition"
                :class="activeTab === tab.key ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
                @click="activeTab = tab.key"
            >
                <component :is="tab.icon" class="size-3.5" /> {{ tab.label }}
            </button>
        </div>

        <!-- TAB: Resumen -->
        <div v-if="activeTab === 'resumen'" class="animate-in fade-in space-y-6 duration-200">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
                <OkrStatCard :icon="CalendarClock" label="Plazo" :value="`${objective.current_week}/${objective.duration_weeks}`" hint="Semana actual / total" />
                <OkrStatCard :icon="Flag" label="Estado" :value="statusLabel[objective.lifecycle_status]" />
                <OkrStatCard :icon="Gauge" label="Cumplimiento" :value="`${objective.compliance}%`" tone="primary" hint="Ponderado por KR" />
                <OkrStatCard :icon="CalendarDays" label="Inicio" :value="formatFriendlyDate(objective.start_date)" />
                <OkrStatCard :icon="CalendarDays" label="Término" :value="formatFriendlyDate(objective.end_date)" />
                <OkrStatCard :icon="Users" label="Key Results" :value="keyResults.length" />
            </div>

            <!-- Contribución sucursal ↔ gestores (sección 25) — solo KPI distribuibles -->
            <section v-for="contribution in objective.contributions ?? []" :key="contribution.kpi.id" class="app-card space-y-3 p-5">
                <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                    <Users class="size-4 text-primary" /> Contribución a la meta — {{ contribution.kpi.name }}
                </p>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div>
                        <p class="text-xs text-muted-foreground">Meta sucursal</p>
                        <p class="font-bold tabular-nums text-foreground">{{ fmt(contribution.branch_target, contribution.kpi.unit) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Suma gestores</p>
                        <p class="font-bold tabular-nums text-foreground">{{ fmt(contribution.children_current_sum, contribution.kpi.unit) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Cobertura</p>
                        <p class="font-bold tabular-nums text-primary">{{ contribution.coverage_percentage === null ? '—' : contribution.coverage_percentage + '%' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Faltante</p>
                        <p class="font-bold tabular-nums" :class="contribution.gap > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'">{{ fmt(Math.abs(contribution.gap), contribution.kpi.unit) }}</p>
                    </div>
                </div>
                <OkrProgressBar :value="contribution.coverage_percentage ?? 0" />
                <div class="space-y-1.5 border-t border-border pt-3">
                    <div v-for="row in contribution.rows" :key="row.employee" class="flex items-center justify-between text-xs">
                        <span class="font-semibold text-foreground">{{ row.employee }}</span>
                        <span class="text-muted-foreground">{{ fmt(row.current_value, contribution.kpi.unit) }} / {{ fmt(row.target_value, contribution.kpi.unit) }} <span class="ml-1 font-semibold text-foreground">({{ row.compliance ?? '—' }}%)</span></span>
                    </div>
                </div>
            </section>

            <section v-if="correctiveActions.length" class="app-card space-y-3 p-5">
                <p class="text-sm font-bold text-foreground">Acciones correctivas pendientes</p>
                <div class="space-y-2">
                    <div v-for="a in correctiveActions" :key="a.id" class="flex items-center justify-between rounded-xl border border-border bg-muted/20 p-3 text-xs">
                        <div>
                            <p class="font-semibold text-foreground">{{ a.description }}</p>
                            <p class="text-muted-foreground">{{ a.responsible }} — vence {{ formatFriendlyDate(a.due_date) }}</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 font-semibold" :class="a.is_overdue ? 'bg-destructive/10 text-destructive' : 'bg-muted text-muted-foreground'">{{ a.is_overdue ? 'Vencida' : a.status }}</span>
                    </div>
                </div>
            </section>

            <section class="app-card space-y-3 p-5">
                <p class="flex items-center gap-1.5 text-sm font-bold text-foreground"><Activity class="size-4 text-primary" /> Actividad reciente</p>
                <div v-if="activityFeed.length" class="space-y-2">
                    <p v-for="(item, i) in activityFeed" :key="i" class="text-xs text-muted-foreground">
                        <span class="font-semibold text-foreground">{{ item.text }}</span> · {{ formatFriendlyDate(item.at?.slice(0, 10)) }}
                    </p>
                </div>
                <p v-else class="text-xs text-muted-foreground">Sin actividad todavía.</p>
                <button type="button" class="text-xs font-bold text-primary hover:underline" @click="activeTab = 'bitacora'">Ver bitácora completa →</button>
            </section>
        </div>

        <!-- TAB: Key Results -->
        <div v-else-if="activeTab === 'keyresults'" class="animate-in fade-in space-y-4 duration-200">
            <div class="app-table-wrap">
                <div class="app-table-content">
                    <table class="w-full min-w-[980px] text-sm">
                        <thead class="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-3 py-3 text-left font-semibold">KPI</th>
                                <th class="px-3 py-3 text-left font-semibold">Base</th>
                                <th class="px-3 py-3 text-left font-semibold">Meta</th>
                                <th class="px-3 py-3 text-left font-semibold">Actual</th>
                                <th class="px-3 py-3 text-left font-semibold">Esperado</th>
                                <th class="px-3 py-3 text-left font-semibold">Desviación</th>
                                <th class="px-3 py-3 text-left font-semibold">Peso</th>
                                <th class="px-3 py-3 text-left font-semibold">Cumplimiento</th>
                                <th class="px-3 py-3 text-left font-semibold">Estado</th>
                                <th class="px-3 py-3 text-left font-semibold"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="kr in keyResults" :key="kr.id"
                                class="cursor-pointer border-t border-border transition-colors hover:bg-muted/30"
                                :class="{ 'bg-primary/5': kr.id === selectedKrId }"
                                @click="selectedKrId = kr.id; activeTab = 'seguimiento'"
                            >
                                <td class="px-3 py-3">
                                    <p class="font-semibold text-foreground">{{ kr.kpi.name }}</p>
                                    <p class="text-xs text-muted-foreground">{{ kr.description }}</p>
                                    <span class="mt-1 inline-block rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">{{ kr.kpi.automation === 'automatic' ? 'Fuente: Reportería' : 'Manual' }}</span>
                                </td>
                                <td class="px-3 py-3 tabular-nums">{{ fmt(kr.baseline_value, kr.kpi.unit) }}</td>
                                <td class="px-3 py-3 tabular-nums">{{ fmt(kr.target_value, kr.kpi.unit) }}</td>
                                <td class="px-3 py-3 font-semibold tabular-nums">{{ fmt(kr.current_value, kr.kpi.unit) }}</td>
                                <td class="px-3 py-3 tabular-nums">{{ fmt(kr.expected_value, kr.kpi.unit) }}</td>
                                <td class="px-3 py-3 font-semibold tabular-nums" :class="(kr.deviation_pp ?? 0) < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'">{{ formatPp(kr.deviation_pp) }}</td>
                                <td class="px-3 py-3 tabular-nums">{{ kr.weight }}%</td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2">
                                        <OkrProgressBar v-if="kr.actual_progress_percentage !== null" :value="kr.actual_progress_percentage" />
                                        <span class="shrink-0 text-xs font-semibold tabular-nums">{{ kr.actual_progress_percentage === null ? '—' : kr.actual_progress_percentage + '%' }}</span>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <OkrWhyPopover
                                        :health="kr.health_status" :expected-progress="kr.expected_progress_percentage"
                                        :actual-progress="kr.actual_progress_percentage" :deviation-pp="kr.deviation_pp"
                                        :weeks-left="weeksLeft" :projected-compliance="kr.projected_compliance_percentage"
                                    />
                                </td>
                                <td class="px-3 py-3" @click.stop>
                                    <button v-if="objective.lifecycle_status === 'active' && canUpdate" class="text-xs font-bold text-primary hover:underline" @click="openEditGoal(kr)">Editar meta</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: Seguimiento -->
        <div v-else-if="activeTab === 'seguimiento'" class="animate-in fade-in space-y-4 duration-200">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="kr in keyResults" :key="kr.id" type="button"
                        class="rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                        :class="kr.id === selectedKrId ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted'"
                        @click="selectedKrId = kr.id"
                    >
                        {{ kr.kpi.name }}
                    </button>
                </div>
                <div class="flex items-center gap-1 rounded-xl border border-border bg-card p-1">
                    <button type="button" class="rounded-lg px-3 py-1 text-xs font-semibold transition" :class="chartMode === 'value' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'" @click="chartMode = 'value'">Valor</button>
                    <button type="button" class="rounded-lg px-3 py-1 text-xs font-semibold transition" :class="chartMode === 'compliance' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'" @click="chartMode = 'compliance'">Cumplimiento %</button>
                </div>
            </div>

            <ChartCard
                v-if="selectedKr && selectedKr.snapshots.length"
                title="Trayectoria semanal" :subtitle="selectedKr.description"
                type="line" :series="chartSeries" :options="chartOptions"
            />
            <p v-else class="text-sm text-muted-foreground">Todavía no hay snapshots para este Key Result.</p>
        </div>

        <!-- TAB: Check-ins -->
        <div v-else-if="activeTab === 'checkins'" class="animate-in fade-in duration-200">
            <div class="grid gap-6 xl:grid-cols-2">
                <section class="app-card space-y-4 p-5">
                    <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                        <ClipboardList class="size-4 text-primary" /> Check-in semanal — Semana {{ objective.current_week }}
                        <OkrHelpTooltip text="Registra qué está frenando el avance y qué acción correctiva se hará. Los KPI automáticos se toman solos de Reportería — aquí solo capturas los manuales y el contexto." />
                    </p>

                    <div v-if="manualKeyResults.length" class="space-y-3 rounded-xl border border-primary/20 bg-primary/5 p-4">
                        <p class="text-xs font-bold uppercase tracking-wide text-primary">Resultados manuales de esta semana</p>
                        <div v-for="kr in manualKeyResults" :key="kr.id" class="space-y-1.5">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-semibold text-foreground">{{ kr.kpi.name }}</span>
                                <span class="text-muted-foreground">Actual anterior: {{ fmt(kr.current_value, kr.kpi.unit) }}</span>
                            </div>
                            <p class="text-xs text-muted-foreground">{{ kr.description }}</p>
                            <div class="relative">
                                <input v-model="manualValues[kr.id]" type="number" step="0.01" placeholder="Resultado de esta semana" class="app-input pr-10">
                                <span v-if="kr.kpi.unit === 'percentage'" class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">%</span>
                                <span v-else-if="kr.kpi.unit === 'currency'" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                            </div>
                        </div>
                    </div>

                    <TextareaField v-model="checkInForm.main_blocker" label="Bloqueo principal" placeholder="¿Qué está frenando el avance?" :rows="2" />
                    <TextareaField v-model="checkInForm.corrective_action" label="Acción correctiva" placeholder="¿Qué se va a hacer al respecto?" :rows="2" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <DatePickerField v-model="checkInForm.action_due_date" label="Fecha compromiso" clearable />
                    </div>
                    <p v-if="Object.keys(checkInForm.errors).length" class="text-xs font-semibold text-destructive">
                        <span v-for="(err, key) in checkInForm.errors" :key="key">{{ err }}<br></span>
                    </p>
                    <Button class="h-10 rounded-2xl" :disabled="checkInForm.processing" @click="submitCheckIn">Registrar check-in</Button>
                </section>

                <section class="app-card space-y-3 p-5">
                    <p class="text-sm font-bold text-foreground">Check-ins anteriores</p>
                    <div v-if="checkIns.length" class="space-y-3">
                        <div v-for="c in checkIns" :key="c.id" class="relative border-l-2 border-border pl-4">
                            <span class="absolute -left-[5px] top-1.5 size-2 rounded-full bg-primary" />
                            <p class="text-xs font-semibold text-foreground">Semana {{ c.week_number }} — {{ c.user }} · {{ formatFriendlyDate(c.check_in_date) }}</p>
                            <p v-if="c.main_blocker" class="mt-0.5 text-xs text-muted-foreground">Bloqueo: {{ c.main_blocker }}</p>
                            <p v-if="c.corrective_action" class="text-xs text-muted-foreground">Acción: {{ c.corrective_action }}</p>
                        </div>
                    </div>
                    <p v-else class="text-xs text-muted-foreground">Sin check-ins todavía.</p>
                </section>
            </div>
        </div>

        <!-- TAB: Evidencias -->
        <div v-else-if="activeTab === 'evidencias'" class="animate-in fade-in duration-200">
            <section class="app-card space-y-3 p-5">
                <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                    <Paperclip class="size-4 text-primary" /> Evidencias y archivos de seguimiento
                </p>

                <label
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-8 text-center transition-colors"
                    :class="isDragging ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40 hover:bg-muted/30'"
                    @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="onDrop"
                >
                    <Upload class="size-6 text-muted-foreground" />
                    <p class="text-sm font-semibold text-foreground">{{ evidenceForm.file ? evidenceForm.file.name : 'Arrastra un archivo aquí' }}</p>
                    <p class="text-xs text-muted-foreground">{{ evidenceForm.file ? formatSize(evidenceForm.file.size) : 'o haz clic para seleccionar' }}</p>
                    <input type="file" class="hidden" @change="onFileChange">
                </label>

                <input v-model="evidenceForm.comment" placeholder="Comentario (opcional)" class="app-input">
                <Button class="h-10 gap-2 rounded-2xl" :disabled="!evidenceForm.file || evidenceForm.processing" @click="submitEvidence">
                    <Upload class="size-3.5" /> Cargar evidencia
                </Button>

                <div v-if="evidences.length" class="mt-2 grid gap-2 border-t border-border pt-3 sm:grid-cols-2">
                    <a v-for="e in evidences" :key="e.id" :href="`/okr/evidences/${e.id}/download`" class="flex items-center gap-3 rounded-xl border border-border bg-muted/20 p-3 text-xs transition hover:bg-muted/40">
                        <FileText class="size-4 shrink-0 text-muted-foreground" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-foreground">{{ e.original_name }}</span>
                            <span class="block text-muted-foreground">{{ e.uploader }} · {{ formatFriendlyDate(e.created_at?.slice(0, 10)) }}</span>
                        </span>
                    </a>
                </div>
                <p v-else class="text-xs text-muted-foreground">Sin evidencias todavía.</p>
            </section>
        </div>

        <!-- TAB: Bitácora -->
        <div v-else-if="activeTab === 'bitacora'" class="animate-in fade-in duration-200">
            <section class="app-card space-y-3 p-5">
                <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                    <History class="size-4 text-primary" /> Historial de cambios
                </p>
                <div v-if="auditLogs.length" class="space-y-3">
                    <div v-for="log in auditLogs" :key="log.id" class="relative border-l-2 border-border pl-4">
                        <span class="absolute -left-[5px] top-1.5 size-2 rounded-full bg-muted-foreground/50" />
                        <p class="text-xs font-semibold text-foreground">
                            {{ fieldLabel[log.field] ?? log.field ?? log.action }} modificada
                            <span v-if="log.old_value !== null && log.new_value !== null" class="font-normal text-muted-foreground">— {{ log.old_value }} → {{ log.new_value }}</span>
                        </p>
                        <p class="text-xs text-muted-foreground">{{ log.user ?? 'Sistema' }} · {{ log.created_at }}</p>
                        <p v-if="log.reason" class="mt-0.5 text-xs italic text-muted-foreground">"{{ log.reason }}"</p>
                    </div>
                </div>
                <p v-else class="text-xs text-muted-foreground">Sin cambios registrados todavía.</p>
            </section>
        </div>

        <!-- Action bar sticky (mobile) -->
        <div class="fixed inset-x-0 bottom-0 z-20 flex items-center justify-around gap-2 border-t border-border bg-card/95 p-3 backdrop-blur lg:hidden">
            <Button v-if="objective.lifecycle_status === 'draft' && canAssign" size="sm" class="h-10 flex-1 gap-1.5 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500" @click="activate">
                <CheckCircle2 class="size-4" /> Activar
            </Button>
            <template v-else>
                <Button size="sm" variant="outline" class="h-10 flex-1 gap-1.5 rounded-xl" @click="activeTab = 'checkins'"><ClipboardList class="size-4" /> Check-in</Button>
                <Button size="sm" variant="outline" class="h-10 flex-1 gap-1.5 rounded-xl" @click="activeTab = 'evidencias'"><Upload class="size-4" /> Evidencia</Button>
                <Button v-if="canUpdate" size="sm" variant="outline" class="h-10 flex-1 gap-1.5 rounded-xl" @click="refresh"><RefreshCw class="size-4" /></Button>
            </template>
        </div>

        <OkrEditGoalDialog v-model:open="editGoalOpen" :objective-id="objective.id" :key-result="editingKr" />
        <OkrWeightsDialog v-model:open="weightsOpen" :objective-id="objective.id" :key-results="keyResults" />
    </div>
</template>
