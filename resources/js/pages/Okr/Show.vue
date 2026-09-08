<script setup lang="ts">
// Módulo OKR — Detalle de Objective (rediseño 08-sep-2026, secciones X-AD del
// pedido). Ancho completo, hero visual, tabla KR moderna, gráfica, check-ins,
// evidencias y bitácora de auditoría como timeline.
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import {
    ArrowLeft, Bell, Building2, CalendarClock, CalendarDays, CheckCircle2, ClipboardList,
    Compass, FileText, Flag, Gauge, History, Paperclip, RefreshCw, Trash2, Upload, UserRound, Users,
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
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
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
}>()

const statusLabel: Record<string, string> = { draft: 'Borrador', active: 'Activo', closed: 'Cerrado', cancelled: 'Cancelado' }

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

// ── Gráfica esperado vs real (por KR seleccionado) ──
const selectedKrId = ref(props.keyResults[0]?.id ?? null)
const selectedKr = computed(() => props.keyResults.find((k) => k.id === selectedKrId.value))
const chartSeries = computed(() => {
    const kr = selectedKr.value
    if (!kr) return []
    return [
        { name: 'Esperado', data: kr.snapshots.map((s: any) => s.expected_value) },
        { name: 'Real', data: kr.snapshots.map((s: any) => s.actual_value) },
    ]
})
const chartOptions = computed(() => ({
    chart: { toolbar: { show: false } },
    xaxis: { categories: (selectedKr.value?.snapshots ?? []).map((s: any) => `Semana ${s.week_number}`) },
    colors: ['#94a3b8', '#4f46e5'],
    stroke: { curve: 'smooth', width: 3 },
    legend: { position: 'top' },
    tooltip: { y: { formatter: (v: number) => fmt(v, selectedKr.value?.kpi?.unit) } },
}))

// ── Check-in ──
const checkInForm = useForm({ main_blocker: '', corrective_action: '', action_responsible_user_id: null as number | null, action_due_date: null as string | null })
function submitCheckIn() {
    checkInForm.post(`/okr/${props.objective.id}/check-ins`, {
        onSuccess: () => { checkInForm.reset(); Swal.fire({ icon: 'success', title: 'Check-in guardado', confirmButtonColor: '#4f46e5', timer: 1800, showConfirmButton: false }) },
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

// ── Editar meta (auditado) ──
const goalForm = useForm({ key_result_id: null as number | null, target_value: '', weight: '', reason: '' })
const editingKr = ref<number | null>(null)
function openEditGoal(kr: any) {
    editingKr.value = kr.id
    goalForm.clearErrors()
    goalForm.key_result_id = kr.id
    goalForm.target_value = kr.target_value
    goalForm.weight = kr.weight
    goalForm.reason = ''
}
function submitGoal() {
    goalForm.put(`/okr/${props.objective.id}/goal`, { onSuccess: () => { editingKr.value = null } })
}

const fieldLabel: Record<string, string> = { target_value: 'Meta', weight: 'Ponderación' }
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3">
            <Link href="/okr" class="text-muted-foreground transition hover:text-foreground"><ArrowLeft class="size-5" /></Link>
            <p class="text-sm text-muted-foreground">Detalle del OKR</p>
        </div>

        <!-- Hero -->
        <section class="app-card space-y-4 p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <OkrStatusBadge kind="lifecycle" :value="objective.lifecycle_status" />
                        <OkrStatusBadge kind="health" :value="objective.health_status" />
                        <OkrStatusBadge v-if="objective.final_status" kind="final" :value="objective.final_status" />
                    </div>
                    <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ objective.title }}</h1>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                        <span class="inline-flex items-center gap-1.5"><Building2 v-if="objective.branch" class="size-3.5" /><Users v-else class="size-3.5" /> {{ objective.branch?.name ?? objective.employee?.full_name ?? '—' }}</span>
                        <span class="inline-flex items-center gap-1.5"><UserRound class="size-3.5" /> Responsable: {{ objective.responsible?.name ?? '—' }}</span>
                        <span class="inline-flex items-center gap-1.5"><CalendarDays class="size-3.5" /> {{ formatFriendlyDate(objective.start_date) }} → {{ formatFriendlyDate(objective.end_date) }}</span>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="objective.lifecycle_status === 'draft'" class="h-10 gap-2 rounded-2xl bg-emerald-600 text-white hover:bg-emerald-500" @click="activate">
                        <CheckCircle2 class="size-4" /> Activar OKR
                    </Button>
                    <Button v-if="objective.lifecycle_status === 'active'" variant="outline" class="h-10 gap-2 rounded-2xl" @click="refresh">
                        <RefreshCw class="size-4" /> Recalcular
                    </Button>
                    <Button v-if="canManage && objective.lifecycle_status !== 'cancelled'" variant="outline" class="h-10 gap-2 rounded-2xl border-destructive/30 text-destructive hover:bg-destructive/10" @click="destroyObjective">
                        <Trash2 class="size-4" /> Eliminar
                    </Button>
                </div>
            </div>

            <p v-if="objective.lifecycle_status === 'draft'" class="flex items-start gap-2 rounded-xl border border-primary/20 bg-primary/5 px-4 py-3 text-xs font-semibold text-primary">
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

        <!-- Métricas -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-6">
            <OkrStatCard :icon="CalendarClock" label="Plazo" :value="`${objective.current_week}/${objective.duration_weeks}`" hint="Semana actual / total" />
            <OkrStatCard :icon="Flag" label="Estado" :value="statusLabel[objective.lifecycle_status]" />
            <OkrStatCard :icon="Gauge" label="Cumplimiento" :value="`${objective.compliance}%`" tone="primary" hint="Ponderado por KR" />
            <OkrStatCard :icon="CalendarDays" label="Inicio" :value="formatFriendlyDate(objective.start_date)" />
            <OkrStatCard :icon="CalendarDays" label="Término" :value="formatFriendlyDate(objective.end_date)" />
            <OkrStatCard :icon="Users" label="Key Results" :value="keyResults.length" />
        </div>

        <!-- Tabla KR -->
        <div class="app-table-wrap">
            <div class="app-table-toolbar">
                <h2 class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                    <ClipboardList class="size-4 text-primary" /> Key Results
                    <OkrHelpTooltip text="Haz clic en una fila para ver su trayectoria semanal en la gráfica de abajo." />
                </h2>
            </div>
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
                            @click="selectedKrId = kr.id"
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
                            <td class="px-3 py-3"><OkrStatusBadge kind="health" :value="kr.health_status" /></td>
                            <td class="px-3 py-3" @click.stop>
                                <button v-if="objective.lifecycle_status === 'active'" class="text-xs font-bold text-primary hover:underline" @click="openEditGoal(kr)">Editar meta</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Editar meta -->
        <div v-if="editingKr" class="app-card animate-in fade-in slide-in-from-top-2 space-y-3 border-primary/20 bg-primary/5 p-5 duration-200">
            <p class="text-sm font-bold text-foreground">Editar meta / peso — se registra en la bitácora</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <input v-model="goalForm.target_value" type="number" step="0.01" placeholder="Nueva meta" class="app-input">
                <input v-model="goalForm.weight" type="number" step="0.01" placeholder="Nuevo peso %" class="app-input">
                <input v-model="goalForm.reason" placeholder="Motivo del cambio (obligatorio)" class="app-input">
            </div>
            <p v-if="goalForm.errors.weight" class="text-xs font-semibold text-destructive">{{ goalForm.errors.weight }}</p>
            <p v-if="goalForm.errors.reason" class="text-xs font-semibold text-destructive">{{ goalForm.errors.reason }}</p>
            <div class="flex gap-2">
                <Button class="h-10 rounded-2xl" :disabled="goalForm.processing" @click="submitGoal">Guardar cambio</Button>
                <Button variant="outline" class="h-10 rounded-2xl" @click="editingKr = null">Cancelar</Button>
            </div>
        </div>

        <!-- Gráfica -->
        <ChartCard
            v-if="selectedKr && selectedKr.snapshots.length"
            title="Trayectoria semanal"
            :subtitle="selectedKr.description"
            type="line" :series="chartSeries" :options="chartOptions"
        />

        <div class="grid gap-6 xl:grid-cols-2">
            <!-- Check-in -->
            <section class="app-card space-y-3 p-5">
                <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                    <ClipboardList class="size-4 text-primary" /> Check-in semanal — Semana {{ objective.current_week }}
                    <OkrHelpTooltip text="Registra qué está frenando el avance y qué acción correctiva se hará. El valor del KPI se toma solo — aquí solo agregas contexto." />
                </p>
                <TextareaField v-model="checkInForm.main_blocker" label="Bloqueo principal" placeholder="¿Qué está frenando el avance?" :rows="2" />
                <TextareaField v-model="checkInForm.corrective_action" label="Acción correctiva" placeholder="¿Qué se va a hacer al respecto?" :rows="2" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <DatePickerField v-model="checkInForm.action_due_date" label="Fecha compromiso" clearable />
                </div>
                <Button class="h-10 rounded-2xl" :disabled="checkInForm.processing" @click="submitCheckIn">Registrar check-in</Button>

                <div v-if="checkIns.length" class="mt-2 space-y-3 border-t border-border pt-3">
                    <div v-for="c in checkIns" :key="c.id" class="relative border-l-2 border-border pl-4">
                        <span class="absolute -left-[5px] top-1.5 size-2 rounded-full bg-primary" />
                        <p class="text-xs font-semibold text-foreground">Semana {{ c.week_number }} — {{ c.user }} · {{ formatFriendlyDate(c.check_in_date) }}</p>
                        <p v-if="c.main_blocker" class="mt-0.5 text-xs text-muted-foreground">Bloqueo: {{ c.main_blocker }}</p>
                        <p v-if="c.corrective_action" class="text-xs text-muted-foreground">Acción: {{ c.corrective_action }}</p>
                    </div>
                </div>
            </section>

            <!-- Evidencias -->
            <section class="app-card space-y-3 p-5">
                <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                    <Paperclip class="size-4 text-primary" /> Evidencias y archivos de seguimiento
                </p>

                <label
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-6 text-center transition-colors"
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

                <div v-if="evidences.length" class="mt-2 space-y-2 border-t border-border pt-3">
                    <a v-for="e in evidences" :key="e.id" :href="`/okr/evidences/${e.id}/download`" class="flex items-center gap-3 rounded-xl border border-border bg-muted/20 p-3 text-xs transition hover:bg-muted/40">
                        <FileText class="size-4 shrink-0 text-muted-foreground" />
                        <span class="flex-1 truncate font-semibold text-foreground">{{ e.original_name }}</span>
                        <span class="shrink-0 text-muted-foreground">{{ e.uploader }} · {{ formatFriendlyDate(e.created_at?.slice(0, 10)) }}</span>
                    </a>
                </div>
            </section>
        </div>

        <!-- Acciones correctivas -->
        <section v-if="correctiveActions.length" class="app-card space-y-3 p-5">
            <p class="text-sm font-bold text-foreground">Acciones correctivas</p>
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

        <!-- Historial / bitácora -->
        <section v-if="auditLogs.length" class="app-card space-y-3 p-5">
            <p class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                <History class="size-4 text-primary" /> Historial de cambios
            </p>
            <div class="space-y-3">
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
        </section>
    </div>
</template>
