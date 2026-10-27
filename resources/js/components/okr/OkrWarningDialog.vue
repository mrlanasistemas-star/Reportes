<script setup lang="ts">
// Evaluar / emitir Warning (15-oct-2026). Flujo: semanas con su elegibilidad
// REAL → detalle del posible incumplimiento (persona, semana, KPI, esperado,
// real, brecha, causa) → acciones correctivas → autorización explícita →
// documento. Una semana abierta o sin dato queda "Pendiente de evaluación".
import { router } from '@inertiajs/vue3'
import { AlertTriangle, CheckCircle2, Clock, Info, Loader2, ShieldAlert } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { formatByUnit, formatFriendlyDate, formatPp } from '@/lib/okrFormat'
import { okrRequest } from '@/lib/okrHttp'

const props = defineProps<{ objectiveId: number; canIssue: boolean }>()
const open = defineModel<boolean>('open', { default: false })

const loading = ref(false)
const weeks = ref<any[]>([])
const selected = ref<number | null>(null)
const corrective = ref('')
const observations = ref('')
const authorized = ref(false)
const submitting = ref(false)
// Bug real corregido (15-oct-2026): un 403/404/500/sin red en eligibility()
// dejaba weeks=[] en silencio — la UI entonces mostraba "Aún no hay semanas
// transcurridas para evaluar", un motivo FALSO que escondía el error real.
const loadError = ref<string | null>(null)
// Situación no evaluable del OKR completo (borrador, cancelado, sin iniciar) —
// se explica, nunca se muestra como error (16-oct-2026).
const notice = ref<{ code: string; message: string } | null>(null)

// Motivo clasificado de cada bloqueo (ver OkrWarningService::eligibility()).
const REASON_LABELS: Record<string, string> = {
    not_active: 'OKR no activado', week_open: 'Semana abierta', no_evidence: 'Sin evidencia suficiente',
    no_breach: 'Cumplió su meta', no_letter: 'Sin Carta Compromiso vigente', no_subject: 'Sin persona evaluada',
    duplicate: 'Documento ya emitido', out_of_range: 'Semana fuera de rango', no_key_results: 'Falta KR',
    no_placement: 'Falta el reporte de colocación', cancelled: 'OKR cancelado', not_started: 'OKR sin iniciar',
}
// "Falta autorización" (15-oct-2026): el backend indica si este usuario puede emitir.
const authorizationNotice = ref<string | null>(null)

async function load() {
    loading.value = true
    loadError.value = null
    notice.value = null
    const res = await okrRequest<{ weeks: any[]; notice?: { code: string; message: string } | null; authorization_notice?: string | null }>(`/okr/${props.objectiveId}/warnings/eligibility`)
    loading.value = false

    if (!res.ok) {
        weeks.value = []
        // Un 500 trae el mensaje con la referencia registrada en el log; un
        // corte de red/403/404 trae el mensaje amigable de okrRequest.
        loadError.value = res.message

        return
    }

    notice.value = res.data.notice ?? null
    authorizationNotice.value = res.data.authorization_notice ?? null
    weeks.value = res.data.weeks ?? []
    selected.value = weeks.value.find((w) => w.status === 'eligible')?.week.number ?? weeks.value[weeks.value.length - 1]?.week.number ?? null
}

watch(open, (v) => {
    if (v) {
        corrective.value = ''
        observations.value = ''
        authorized.value = false
        loadError.value = null
        load()
    }
})

const current = computed(() => weeks.value.find((w) => w.week.number === selected.value) ?? null)

const STATUS: Record<string, { label: string; class: string; icon: any }> = {
    eligible: { label: 'Incumplimiento', class: 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/30', icon: ShieldAlert },
    pending: { label: 'Pendiente de evaluación', class: 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30', icon: Clock },
    not_eligible: { label: 'Cumplió lo esperado', class: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30', icon: CheckCircle2 },
    blocked: { label: 'Incumplimiento · no emitible', class: 'bg-orange-500/10 text-orange-700 dark:text-orange-300 border-orange-500/30', icon: AlertTriangle },
    unavailable: { label: 'No evaluable', class: 'bg-muted text-muted-foreground border-border', icon: Info },
}

function chip(w: any) {
    if (w.status === 'not_eligible' && w.has_breach) {
        return STATUS.blocked
    }

    if (w.status === 'not_eligible' && !(w.reasons ?? []).includes('no_breach')) {
        return STATUS.unavailable
    }

    return STATUS[w.status] ?? STATUS.unavailable
}

async function submit() {
    if (!current.value || current.value.status !== 'eligible') {
        return
    }

    submitting.value = true
    const res = await okrRequest(`/okr/${props.objectiveId}/warnings`, {
        method: 'POST',
        json: { week_number: current.value.week.number, corrective_actions: corrective.value, observations: observations.value || null, authorized: authorized.value },
    })
    submitting.value = false

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se emitió el Warning', text: res.message })

        return
    }

    open.value = false
    Swal.fire({ icon: 'success', title: 'Warning emitido', text: `Folio ${res.data.warning.folio} a nombre de ${res.data.warning.collaborator}.` })
    router.reload({ only: ['warnings', 'objective'] })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] w-[96vw] max-w-3xl overflow-y-auto sm:max-w-3xl">
            <DialogTitle class="flex items-center gap-2 text-base font-bold"><ShieldAlert class="size-5 text-rose-600" /> Evaluar incumplimiento</DialogTitle>
            <p class="-mt-1 text-xs text-muted-foreground">El sistema solo detecta; emitir un Warning requiere revisar los datos y autorizarlo. Se evalúan semanas cerradas contra lo esperado a esa semana, nunca contra la meta final.</p>

            <div v-if="loading" class="space-y-2"><div v-for="n in 3" :key="n" class="h-10 app-skeleton rounded-lg bg-muted" /></div>

            <div v-else-if="loadError" class="space-y-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                <p class="text-[10px] font-bold uppercase tracking-wide">Error técnico</p>
                <p>{{ loadError }}</p>
                <Button size="sm" variant="outline" class="rounded-lg" @click="load">Reintentar</Button>
            </div>

            <div v-else-if="notice" class="flex items-start gap-2 rounded-xl border border-sky-500/30 bg-sky-500/5 p-3 text-sm text-sky-800 dark:text-sky-200">
                <Info class="mt-0.5 size-4 shrink-0" />
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wide">{{ REASON_LABELS[notice.code] ?? 'No evaluable' }}</p>
                    <p>{{ notice.message }}</p>
                </div>
            </div>

            <template v-else>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="w in weeks" :key="w.week.number" type="button"
                        class="flex items-center gap-1.5 rounded-full border px-3 py-1 text-[11px] font-semibold transition hover:-translate-y-0.5"
                        :class="[chip(w).class, selected === w.week.number ? 'ring-2 ring-primary/40' : '']"
                        @click="selected = w.week.number"
                    >
                        <component :is="chip(w).icon" class="size-3.5" /> S{{ w.week.number }} · {{ chip(w).label }}
                    </button>
                </div>

                <div v-if="current" class="space-y-3 animate-in fade-in duration-200">
                    <div class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-3">
                        <div class="rounded-xl border border-border p-2.5"><p class="text-[10px] font-bold uppercase text-muted-foreground">Colaborador</p><p class="font-semibold text-foreground">{{ current.subject?.full_name ?? 'Sin persona evaluada' }}</p></div>
                        <div class="rounded-xl border border-border p-2.5"><p class="text-[10px] font-bold uppercase text-muted-foreground">Semana evaluada</p><p class="font-semibold text-foreground">S{{ current.week.number }} · {{ formatFriendlyDate(current.week.start) }} – {{ formatFriendlyDate(current.week.end) }}</p></div>
                        <div class="rounded-xl border border-border p-2.5"><p class="text-[10px] font-bold uppercase text-muted-foreground">Carta Compromiso</p><p class="font-semibold text-foreground">{{ current.letter ? `${current.letter.folio} · ${formatFriendlyDate(current.letter.date)}` : 'No emitida' }}</p></div>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-border">
                        <table class="w-full min-w-[620px] text-xs">
                            <thead class="bg-muted/40 text-muted-foreground"><tr>
                                <th class="px-2.5 py-2 text-left">KPI</th><th class="px-2.5 py-2 text-right">Meta final</th><th class="px-2.5 py-2 text-right">Esperado a la semana</th>
                                <th class="px-2.5 py-2 text-right">Real</th><th class="px-2.5 py-2 text-right">Brecha</th><th class="px-2.5 py-2 text-right">Desviación</th>
                            </tr></thead>
                            <tbody>
                                <tr v-for="r in current.rows" :key="r.kpi_name" class="border-t border-border" :class="r.has_breach ? 'bg-rose-500/5' : ''">
                                    <td class="px-2.5 py-2 font-semibold text-foreground">{{ r.kpi_name }} <AlertTriangle v-if="r.has_breach" class="inline size-3.5 text-rose-600" /></td>
                                    <td class="px-2.5 py-2 text-right tabular-nums">{{ formatByUnit(r.target_value, r.unit) }}</td>
                                    <td class="px-2.5 py-2 text-right tabular-nums">{{ formatByUnit(r.expected_value, r.unit) }}</td>
                                    <td class="px-2.5 py-2 text-right font-semibold tabular-nums">{{ r.has_data ? formatByUnit(r.actual_value, r.unit) : 'Sin dato' }}</td>
                                    <td class="px-2.5 py-2 text-right tabular-nums">{{ r.gap === null ? '—' : formatByUnit(r.gap, r.unit) }}</td>
                                    <td class="px-2.5 py-2 text-right font-semibold tabular-nums" :class="(r.deviation_pp ?? 0) < 0 ? 'text-rose-600' : 'text-emerald-600'">{{ formatPp(r.deviation_pp) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="current.blockers.length" class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-xs text-amber-800 dark:text-amber-200">
                        <p class="mb-1 font-bold">{{ current.reasons?.includes('no_breach') && current.reasons.length === 1 ? 'No corresponde emitir un Warning:' : 'Todavía no se puede emitir un Warning para esta semana:' }}</p>
                        <div class="mb-1.5 flex flex-wrap gap-1">
                            <span v-for="r in current.reasons ?? []" :key="r" class="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-bold">{{ REASON_LABELS[r] ?? r }}</span>
                        </div>
                        <ul class="space-y-0.5 pl-4"><li v-for="b in current.blockers" :key="b" class="list-disc">{{ b }}</li></ul>
                    </div>

                    <div v-else-if="!canIssue || authorizationNotice" class="flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-xs text-amber-800 dark:text-amber-200">
                        <Info class="mt-0.5 size-4 shrink-0" />
                        <p><strong>Falta autorización.</strong> {{ authorizationNotice ?? 'Puedes consultar la evaluación, pero emitir un Warning requiere permiso de administrador o gerencia.' }}</p>
                    </div>

                    <div v-else class="space-y-2 rounded-xl border border-rose-500/30 bg-rose-500/5 p-3">
                        <p class="text-xs font-bold text-rose-700 dark:text-rose-300">Causa: resultado real por debajo de lo esperado a la semana {{ current.week.number }} (desviación negativa) con la semana cerrada.</p>
                        <textarea v-model="corrective" rows="3" maxlength="2000" class="app-textarea text-sm" placeholder="Acciones correctivas acordadas (obligatorio)" />
                        <textarea v-model="observations" rows="2" maxlength="2000" class="app-textarea text-sm" placeholder="Observaciones de la empresa (opcional)" />
                        <p v-if="!authorized" class="text-[10px] font-semibold text-amber-700 dark:text-amber-300">Falta autorización: marca la confirmación para poder emitir.</p>
                        <label class="flex cursor-pointer items-start gap-2 text-xs text-foreground">
                            <input v-model="authorized" type="checkbox" class="mt-0.5 accent-rose-600">
                            <span>Revisé los datos y <strong>autorizo</strong> emitir el Warning a nombre de <strong>{{ current.subject?.full_name }}</strong>.</span>
                        </label>
                        <div class="flex justify-end">
                            <Button class="gap-1.5 rounded-xl bg-rose-600 text-white hover:bg-rose-500" :disabled="!authorized || corrective.trim().length < 5 || submitting" @click="submit">
                                <Loader2 v-if="submitting" class="size-4 animate-spin" /><ShieldAlert v-else class="size-4" /> {{ submitting ? 'Generando…' : 'Emitir Warning' }}
                            </Button>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">Aún no hay semanas transcurridas para evaluar.</p>
            </template>
        </DialogContent>
    </Dialog>
</template>
