<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router, useForm } from '@inertiajs/vue3'
import { ArrowLeft, RefreshCw, CheckCircle2, Upload, Paperclip, Bell } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import ChartCard from '@/components/radiography/ChartCard.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    objective: any
    keyResults: any[]
    checkIns: any[]
    correctiveActions: any[]
    evidences: any[]
    alerts: any[]
}>()

const healthLabel: Record<string, string> = { ahead: 'Adelantado', on_track: 'En trayectoria', risk: 'En riesgo', off_track: 'Fuera de trayectoria' }
const healthColor: Record<string, string> = {
    ahead: 'text-emerald-700 bg-emerald-100', on_track: 'text-sky-700 bg-sky-100',
    risk: 'text-amber-700 bg-amber-100', off_track: 'text-rose-700 bg-rose-100',
}
const statusLabel: Record<string, string> = { draft: 'Borrador', active: 'Activo', closed: 'Cerrado', cancelled: 'Cancelado' }

function fmt(v: number | null, unit?: string) {
    if (v === null || v === undefined) return '—'
    if (unit === 'percentage') return `${Number(v).toFixed(2)}%`
    if (unit === 'currency') return `$${Number(v).toLocaleString('es-MX', { minimumFractionDigits: 2 })}`
    return Number(v).toLocaleString('es-MX')
}

function activate() {
    router.post(`/okr/${props.objective.id}/activate`)
}
function refresh() {
    router.post(`/okr/${props.objective.id}/refresh`)
}

// ── Gráfica esperado vs real (por KR seleccionado) ──
const selectedKrId = ref(props.keyResults[0]?.id ?? null)
const selectedKr = computed(() => props.keyResults.find(k => k.id === selectedKrId.value))
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
    colors: ['#94a3b8', '#4338ca'],
    stroke: { curve: 'smooth', width: 3 },
    legend: { position: 'top' },
    tooltip: { y: { formatter: (v: number) => fmt(v, selectedKr.value?.kpi?.unit) } },
}))

// ── Check-in ──
const checkInForm = useForm({ main_blocker: '', corrective_action: '', action_responsible_user_id: '', action_due_date: '' })
function submitCheckIn() {
    checkInForm.post(`/okr/${props.objective.id}/check-ins`, { onSuccess: () => checkInForm.reset() })
}

// ── Evidencia ──
const evidenceForm = useForm({ file: null as File | null, comment: '', okr_key_result_id: null as number | null })
function onFileChange(e: Event) {
    evidenceForm.file = (e.target as HTMLInputElement).files?.[0] ?? null
}
function submitEvidence() {
    evidenceForm.post(`/okr/${props.objective.id}/evidences`, { onSuccess: () => evidenceForm.reset() })
}

// ── Editar meta (auditado) ──
const goalForm = useForm({ key_result_id: null as number | null, target_value: '', weight: '', reason: '' })
const editingKr = ref<number | null>(null)
function openEditGoal(kr: any) {
    editingKr.value = kr.id
    goalForm.key_result_id = kr.id
    goalForm.target_value = kr.target_value
    goalForm.weight = kr.weight
    goalForm.reason = ''
}
function submitGoal() {
    goalForm.put(`/okr/${props.objective.id}/goal`, { onSuccess: () => { editingKr.value = null } })
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
        <div class="flex items-center gap-3">
            <Link href="/okr" class="text-slate-400 hover:text-slate-700"><ArrowLeft class="size-5" /></Link>
            <div class="flex-1">
                <h1 class="text-xl font-black text-slate-950">{{ objective.title }}</h1>
                <p class="text-xs text-slate-500">{{ objective.branch?.name ?? objective.employee?.full_name }} · Responsable: {{ objective.responsible?.name ?? '—' }}</p>
            </div>
            <button v-if="objective.lifecycle_status === 'draft'" @click="activate" class="inline-flex h-9 items-center gap-2 rounded-xl bg-emerald-600 px-4 text-xs font-black text-white hover:bg-emerald-500">
                <CheckCircle2 class="size-4" /> Activar OKR
            </button>
            <button v-if="objective.lifecycle_status === 'active'" @click="refresh" class="inline-flex h-9 items-center gap-2 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600 hover:bg-slate-50">
                <RefreshCw class="size-4" /> Recalcular
            </button>
        </div>

        <div v-if="alerts.length" class="space-y-1">
            <div v-for="a in alerts" :key="a.id" class="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-700">
                <Bell class="size-3.5 shrink-0" /> {{ a.message }}
            </div>
        </div>

        <p v-if="objective.lifecycle_status === 'draft'" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-xs font-bold text-indigo-700">
            OKR en borrador — revisa la línea base y ponderación antes de activar. Al activar, la línea base queda CONGELADA.
            Ponderación actual: {{ objective.weight_summary.total }}% ({{ objective.weight_summary.is_valid ? 'lista para activar' : (objective.weight_summary.remaining > 0 ? objective.weight_summary.remaining + '% pendiente' : Math.abs(objective.weight_summary.remaining) + '% excedido') }}).
        </p>

        <!-- Cards de seguimiento -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-bold text-slate-500">Plazo</p>
                <p class="mt-1 text-lg font-black text-slate-950">{{ objective.current_week }}/{{ objective.duration_weeks }} sem.</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-bold text-slate-500">Estado</p>
                <p class="mt-1 text-lg font-black text-slate-950">{{ statusLabel[objective.lifecycle_status] }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-bold text-slate-500">Cumplimiento</p>
                <p class="mt-1 text-lg font-black text-indigo-700">{{ objective.compliance }}%</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-bold text-slate-500">Semáforo</p>
                <span v-if="objective.health_status" :class="healthColor[objective.health_status]" class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-bold">{{ healthLabel[objective.health_status] }}</span>
                <span v-else class="text-sm text-slate-400">—</span>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-bold text-slate-500">Inicio</p>
                <p class="mt-1 text-sm font-bold text-slate-800">{{ objective.start_date }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <p class="text-xs font-bold text-slate-500">Término</p>
                <p class="mt-1 text-sm font-bold text-slate-800">{{ objective.end_date }}</p>
            </div>
        </div>

        <!-- Tabla Key Results -->
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[900px] text-sm">
                <thead class="bg-slate-900 text-white">
                    <tr>
                        <th class="px-3 py-3 text-left font-bold">KPI</th>
                        <th class="px-3 py-3 text-left font-bold">Base</th>
                        <th class="px-3 py-3 text-left font-bold">Meta</th>
                        <th class="px-3 py-3 text-left font-bold">Actual</th>
                        <th class="px-3 py-3 text-left font-bold">Esperado</th>
                        <th class="px-3 py-3 text-left font-bold">Desviación</th>
                        <th class="px-3 py-3 text-left font-bold">Peso</th>
                        <th class="px-3 py-3 text-left font-bold">Cumplimiento</th>
                        <th class="px-3 py-3 text-left font-bold">Proyección</th>
                        <th class="px-3 py-3 text-left font-bold"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="kr in keyResults" :key="kr.id" class="cursor-pointer border-t border-slate-100 hover:bg-slate-50" :class="{ 'bg-indigo-50/40': kr.id === selectedKrId }" @click="selectedKrId = kr.id">
                        <td class="px-3 py-3">
                            <p class="font-bold text-slate-900">{{ kr.kpi.name }}</p>
                            <p class="text-xs text-slate-400">{{ kr.description }}</p>
                            <span class="mt-0.5 inline-block rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">{{ kr.kpi.automation === 'automatic' ? 'Fuente: Reportería' : 'Manual' }}</span>
                        </td>
                        <td class="px-3 py-3">{{ fmt(kr.baseline_value, kr.kpi.unit) }}</td>
                        <td class="px-3 py-3">{{ fmt(kr.target_value, kr.kpi.unit) }}</td>
                        <td class="px-3 py-3 font-bold">{{ fmt(kr.current_value, kr.kpi.unit) }}</td>
                        <td class="px-3 py-3">{{ fmt(kr.expected_value, kr.kpi.unit) }}</td>
                        <td class="px-3 py-3 font-bold" :class="(kr.deviation_pp ?? 0) < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ kr.deviation_pp === null ? '—' : (kr.deviation_pp > 0 ? '+' : '') + kr.deviation_pp + ' pp' }}</td>
                        <td class="px-3 py-3">{{ kr.weight }}%</td>
                        <td class="px-3 py-3 font-bold">{{ kr.actual_progress_percentage === null ? '—' : kr.actual_progress_percentage + '%' }}</td>
                        <td class="px-3 py-3">{{ kr.projected_compliance_percentage === null ? '—' : kr.projected_compliance_percentage + '%' }}</td>
                        <td class="px-3 py-3" @click.stop>
                            <button v-if="objective.lifecycle_status === 'active'" @click="openEditGoal(kr)" class="text-xs font-bold text-indigo-600 hover:underline">Editar meta</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Editar meta modal simple -->
        <div v-if="editingKr" class="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-4 space-y-3">
            <p class="text-sm font-black text-indigo-800">Editar meta / peso — se registra en la bitácora</p>
            <div class="grid gap-3 sm:grid-cols-3">
                <input v-model="goalForm.target_value" type="number" step="0.01" placeholder="Nueva meta" class="h-10 rounded-xl border border-slate-200 px-3 text-sm" />
                <input v-model="goalForm.weight" type="number" step="0.01" placeholder="Nuevo peso %" class="h-10 rounded-xl border border-slate-200 px-3 text-sm" />
                <input v-model="goalForm.reason" placeholder="Motivo del cambio (obligatorio)" class="h-10 rounded-xl border border-slate-200 px-3 text-sm" />
            </div>
            <div class="flex gap-2">
                <button @click="submitGoal" class="h-9 rounded-xl bg-indigo-700 px-4 text-xs font-black text-white">Guardar cambio</button>
                <button @click="editingKr = null" class="h-9 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600">Cancelar</button>
            </div>
        </div>

        <!-- Gráfica trayectoria -->
        <ChartCard v-if="selectedKr && selectedKr.snapshots.length" title="Trayectoria esperada vs resultado real" :subtitle="selectedKr.description"
                   type="line" :series="chartSeries" :options="chartOptions" />

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Check-in -->
            <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-black text-slate-950">Check-in semanal</p>
                <textarea v-model="checkInForm.main_blocker" rows="2" placeholder="Principal bloqueo" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                <textarea v-model="checkInForm.corrective_action" rows="2" placeholder="Acción correctiva" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                <div class="grid gap-2 sm:grid-cols-2">
                    <input v-model="checkInForm.action_due_date" type="date" class="h-10 rounded-xl border border-slate-200 px-3 text-sm" />
                </div>
                <button @click="submitCheckIn" :disabled="checkInForm.processing" class="h-9 rounded-xl bg-indigo-700 px-4 text-xs font-black text-white disabled:opacity-40">Registrar check-in</button>

                <div class="mt-3 space-y-2">
                    <div v-for="c in checkIns" :key="c.id" class="rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs">
                        <p class="font-bold text-slate-700">Semana {{ c.week_number }} — {{ c.user }} ({{ c.check_in_date }})</p>
                        <p v-if="c.main_blocker" class="text-slate-500">Bloqueo: {{ c.main_blocker }}</p>
                        <p v-if="c.corrective_action" class="text-slate-500">Acción: {{ c.corrective_action }}</p>
                    </div>
                </div>
            </div>

            <!-- Evidencias -->
            <div class="space-y-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-black text-slate-950">Evidencias y archivos de seguimiento</p>
                <input type="file" @change="onFileChange" class="w-full text-xs" />
                <input v-model="evidenceForm.comment" placeholder="Comentario (opcional)" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" />
                <button @click="submitEvidence" :disabled="!evidenceForm.file || evidenceForm.processing" class="inline-flex h-9 items-center gap-2 rounded-xl bg-slate-800 px-4 text-xs font-black text-white disabled:opacity-40">
                    <Upload class="size-3.5" /> Cargar evidencia
                </button>

                <div class="mt-3 space-y-2">
                    <a v-for="e in evidences" :key="e.id" :href="`/okr/evidences/${e.id}/download`" class="flex items-center gap-2 rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs hover:bg-slate-100">
                        <Paperclip class="size-3.5 shrink-0 text-slate-400" />
                        <span class="flex-1 font-bold text-slate-700">{{ e.original_name }}</span>
                        <span class="text-slate-400">{{ e.uploader }}</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Acciones correctivas -->
        <div v-if="correctiveActions.length" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="mb-3 text-sm font-black text-slate-950">Acciones correctivas</p>
            <div class="space-y-2">
                <div v-for="a in correctiveActions" :key="a.id" class="flex items-center justify-between rounded-xl border border-slate-100 bg-slate-50 p-3 text-xs">
                    <div>
                        <p class="font-bold text-slate-700">{{ a.description }}</p>
                        <p class="text-slate-400">{{ a.responsible }} — vence {{ a.due_date }}</p>
                    </div>
                    <span class="rounded-full px-2 py-0.5 font-bold" :class="a.is_overdue ? 'bg-rose-100 text-rose-700' : 'bg-slate-200 text-slate-600'">{{ a.is_overdue ? 'Vencida' : a.status }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
