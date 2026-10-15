<script setup lang="ts">
// Revisión de la Carta Compromiso (15-oct-2026). Muestra a la PERSONA
// EVALUADA (gestor del OKR individual o titular del OKR de sucursal — nunca
// quien creó el OKR), su puesto vigente con su fuente, la sugerencia de jefe
// inmediato (que se confirma o corrige), objetivo, KR, base, meta y periodo.
// Solo puesto (si el directorio no lo tiene), jefe inmediato y observaciones
// son capturables; identidad y metas congeladas no. Incluye el historial de
// cartas (vigente/anuladas) con anulación auditada.
import { router } from '@inertiajs/vue3'
import { AlertTriangle, Ban, CheckCircle2, Download, Eye, FileSignature, Lightbulb, Loader2, UserCheck } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, reactive, ref, watch } from 'vue'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { formatByUnit, formatFriendlyDate, formatFriendlyDateTime } from '@/lib/okrFormat'
import { okrRequest } from '@/lib/okrHttp'

const props = defineProps<{ objectiveId: number; scopeType: string; branchId: number | null; canIssue: boolean }>()
const open = defineModel<boolean>('open', { default: false })

const fields = reactive({ position: '', immediate_boss: '', observations: '' })
const review = ref<any | null>(null)
const loading = ref(false)
const generating = ref(false)
const accountableId = ref<number | null>(null)
const savingAccountable = ref(false)
const branchEmployees = ref<{ id: number; full_name: string }[]>([])
let timer: ReturnType<typeof setTimeout> | null = null
let version = 0

function query(): string {
    const params = new URLSearchParams()
    Object.entries(fields).forEach(([k, v]) => v.trim() && params.set(k, v.trim()))

    return params.toString()
}

async function loadReview() {
    const mine = ++version
    loading.value = true
    const res = await okrRequest(`/okr/${props.objectiveId}/commitment-letter/review?${query()}`)

    if (mine !== version) {
        return
    }

    loading.value = false
    review.value = res.ok ? res.data : null
}

async function loadBranchEmployees() {
    if (props.scopeType !== 'branch' || !props.branchId) {
        return
    }

    const res = await okrRequest(`/okr/employees-lookup?branch_id=${props.branchId}`)
    branchEmployees.value = res.ok ? (res.data.employees ?? []).map((e: any) => ({ id: e.id, full_name: e.position ? `${e.full_name} · ${e.position}` : e.full_name })) : []
}

watch(open, (v) => {
    if (v) {
        fields.position = ''
        fields.immediate_boss = ''
        fields.observations = ''
        accountableId.value = null
        loadReview()
        loadBranchEmployees()
    }
})
watch(fields, () => {
    if (timer) {
        clearTimeout(timer)
    }

    timer = setTimeout(loadReview, 400)
})

const snap = computed(() => review.value?.snapshot ?? null)
const ctx = computed(() => review.value?.context ?? null)
const missing = computed<Record<string, string>>(() => review.value?.missing ?? {})

async function assignAccountable(id: number | null) {
    if (!id) {
        return
    }

    savingAccountable.value = true
    const res = await okrRequest(`/okr/${props.objectiveId}/accountable`, { method: 'PUT', json: { employee_id: id } })
    savingAccountable.value = false

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se asignó el titular', text: res.message })

        return
    }

    await loadReview()
    router.reload({ only: ['objective'] })
}

function useBossSuggestion() {
    if (ctx.value?.boss_suggestion?.name) {
        fields.immediate_boss = ctx.value.boss_suggestion.name
    }
}

// Confirmaciones DENTRO del diálogo (15-oct-2026): un SweetAlert encima de un
// Dialog abierto no recibe clics/foco — el botón "Sí, emitir" no respondía.
const confirmingIssue = ref(false)
const voidingId = ref<number | null>(null)
const voidReason = ref('')
const voiding = ref(false)

async function generate() {
    generating.value = true
    const res = await okrRequest(`/okr/${props.objectiveId}/commitment-letter`, { method: 'POST', json: { ...fields } })
    generating.value = false
    confirmingIssue.value = false

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se pudo emitir la carta', text: res.message })

        return
    }

    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `Carta emitida · ${res.data.letter.folio}`, timer: 2600, showConfirmButton: false })
    await loadReview()
    router.reload({ only: ['objective'] })
}

async function voidLetter(letter: any) {
    voiding.value = true
    const res = await okrRequest(`/okr/commitment-letters/${letter.id}/void`, { method: 'POST', json: { reason: voidReason.value } })
    voiding.value = false

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se anuló la carta', text: res.message })

        return
    }

    voidingId.value = null
    voidReason.value = ''
    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `${letter.folio} anulada`, timer: 2500, showConfirmButton: false })
    await loadReview()
    router.reload({ only: ['objective'] })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] w-[96vw] max-w-3xl overflow-y-auto sm:max-w-3xl">
            <DialogTitle class="flex items-center gap-2 text-base font-bold"><FileSignature class="size-5 text-primary" /> Carta Compromiso — revisión</DialogTitle>

            <div v-if="!review && loading" class="space-y-2 py-2"><div v-for="n in 4" :key="n" class="h-10 animate-pulse rounded-lg bg-muted" /></div>

            <div v-else-if="snap" class="space-y-4">
                <!-- Persona evaluada -->
                <section class="rounded-2xl border border-border p-3">
                    <p class="mb-2 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground"><UserCheck class="size-3.5" /> {{ scopeType === 'branch' ? 'Titular del OKR de sucursal' : 'Colaborador evaluado' }}</p>
                    <template v-if="snap.collaborator_name">
                        <p class="text-base font-bold text-foreground">{{ snap.collaborator_name }}</p>
                        <p class="text-xs text-muted-foreground">Sucursal {{ snap.branch_name }} · Responsable de seguimiento (administrativo): {{ snap.responsible_name ?? '—' }}</p>
                    </template>
                    <div v-else-if="scopeType === 'branch'" class="space-y-2">
                        <p class="text-xs text-amber-700 dark:text-amber-300">Este OKR de sucursal no tiene titular. La carta general es de la persona que acepta la responsabilidad de la sucursal (normalmente el gerente), nunca de quien creó el OKR.</p>
                        <button v-if="ctx?.accountable_suggestion" type="button" class="flex w-full items-center gap-2 rounded-xl border border-primary/30 bg-primary/5 p-2.5 text-left text-xs transition hover:bg-primary/10" :disabled="savingAccountable || !canIssue" @click="assignAccountable(ctx.accountable_suggestion.employee_id)">
                            <Lightbulb class="size-4 text-primary" />
                            <span class="flex-1">Sugerido: <strong>{{ ctx.accountable_suggestion.name }}</strong> ({{ ctx.accountable_suggestion.position }} activo de la sucursal según el directorio vigente)</span>
                            <span class="font-bold text-primary">Asignar</span>
                        </button>
                        <div v-if="canIssue" class="flex items-end gap-2">
                            <div class="flex-1"><SearchableSelect v-model="accountableId as any" placeholder="Elegir titular (colaboradores vigentes)" :options="branchEmployees" label-key="full_name" secondary-key="__none" /></div>
                            <Button size="sm" class="h-10 rounded-xl" :disabled="!accountableId || savingAccountable" @click="assignAccountable(accountableId)"><Loader2 v-if="savingAccountable" class="size-4 animate-spin" /> Asignar titular</Button>
                        </div>
                    </div>
                </section>

                <!-- Datos laborales -->
                <section class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border p-3" :class="missing.position ? 'border-amber-500/40 bg-amber-500/5' : 'border-border'">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Puesto</p>
                        <template v-if="snap.position && !fields.position">
                            <p class="text-sm font-semibold text-foreground">{{ snap.position }}</p>
                            <p class="text-[10px] text-muted-foreground">{{ snap.position_source }}</p>
                        </template>
                        <template v-else>
                            <input v-model="fields.position" maxlength="120" class="app-input mt-1 h-9 text-sm" placeholder="Sin puesto en el directorio — captúralo">
                            <p class="mt-0.5 text-[10px] text-muted-foreground">Solo editable porque el directorio laboral vigente no lo tiene.</p>
                        </template>
                    </div>
                    <div class="rounded-xl border p-3" :class="missing.immediate_boss ? 'border-amber-500/40 bg-amber-500/5' : 'border-border'">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Jefe inmediato (confirmar)</p>
                        <input v-model="fields.immediate_boss" maxlength="160" class="app-input mt-1 h-9 text-sm" placeholder="Nombre del jefe inmediato real">
                        <div v-if="ctx?.boss_suggestion" class="mt-1.5 text-[11px]">
                            <button v-if="ctx.boss_suggestion.name && fields.immediate_boss !== ctx.boss_suggestion.name" type="button" class="flex items-center gap-1 font-semibold text-primary hover:underline" @click="useBossSuggestion">
                                <Lightbulb class="size-3.5" /> Usar sugerencia: {{ ctx.boss_suggestion.name }} ({{ ctx.boss_suggestion.position }})
                            </button>
                            <p class="text-muted-foreground">{{ ctx.boss_suggestion.basis }}</p>
                        </div>
                    </div>
                </section>

                <!-- Objetivo y KR -->
                <section class="rounded-2xl border border-border p-3">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Objetivo · {{ formatFriendlyDate(snap.start_date) }} al {{ formatFriendlyDate(snap.end_date) }}</p>
                    <p class="text-sm font-semibold text-foreground">{{ snap.objective_title }}</p>
                    <div class="mt-2 space-y-1.5">
                        <div v-for="(kr, i) in snap.key_results" :key="i" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/40 px-2.5 py-1.5 text-xs">
                            <span class="font-semibold text-foreground">{{ kr.kpi_name }} <span class="font-normal text-muted-foreground">· {{ kr.description }} · {{ kr.weight }}%</span></span>
                            <span class="tabular-nums text-muted-foreground">Base {{ kr.baseline_value === null ? 'se congela al activar' : formatByUnit(kr.baseline_value, kr.unit) }} → <strong class="text-primary">Meta {{ formatByUnit(kr.target_value, kr.unit) }}</strong></span>
                        </div>
                        <p v-if="!snap.key_results.length" class="text-xs text-amber-700 dark:text-amber-300">Este OKR no tiene Key Results configurados.</p>
                    </div>
                    <textarea v-model="fields.observations" rows="2" maxlength="600" class="app-textarea mt-2 text-sm" placeholder="Observaciones (opcional, aparecen debajo de la tabla de KPI)" />
                </section>

                <div v-if="Object.keys(missing).length" class="flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-xs text-amber-800 dark:text-amber-200">
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" /><span>Para emitir falta: <strong>{{ Object.values(missing).join(', ') }}</strong>.</span>
                </div>
                <div v-else class="flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                    <CheckCircle2 class="size-4" /> Datos completos.
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <a :href="`/okr/${objectiveId}/commitment-letter/preview?${query()}`" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-border px-3 py-2 text-xs font-semibold text-foreground transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-sm"><Eye class="size-4" /> Vista previa PDF</a>
                    <Button
                        v-if="canIssue && !review.has_current_letter && !confirmingIssue" class="h-10 gap-1.5 rounded-xl bg-emerald-600 text-white shadow-md hover:bg-emerald-500"
                        :disabled="!review.can_generate || loading" @click="confirmingIssue = true"
                    >
                        <FileSignature class="size-4" /> Emitir carta definitiva
                    </Button>
                </div>
                <div v-if="confirmingIssue" class="flex flex-wrap items-center gap-3 rounded-xl border border-emerald-500/40 bg-emerald-500/5 p-3 text-xs animate-in fade-in slide-in-from-bottom-1">
                    <span class="flex-1 text-foreground">Se emitirá a nombre de <strong>{{ snap.collaborator_name }}</strong> con folio definitivo; si la meta cambia después, esta carta no se modifica.</span>
                    <Button variant="ghost" size="sm" class="rounded-lg" :disabled="generating" @click="confirmingIssue = false">Revisar de nuevo</Button>
                    <Button size="sm" class="gap-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-500" :disabled="generating" @click="generate">
                        <Loader2 v-if="generating" class="size-4 animate-spin" /> {{ generating ? 'Generando PDF…' : 'Sí, emitir' }}
                    </Button>
                </div>
                <p v-if="!review.is_activated" class="text-[11px] text-muted-foreground">La carta definitiva se emite cuando el OKR esté activado (línea base congelada). La vista previa ya está disponible.</p>

                <!-- Historial -->
                <section v-if="review.letters?.length" class="space-y-1.5 border-t border-border pt-3">
                    <p class="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Historial de cartas</p>
                    <div v-for="l in review.letters" :key="l.id" class="flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2 text-xs" :class="l.status === 'voided' ? 'border-border bg-muted/30 opacity-80' : 'border-emerald-500/30 bg-emerald-500/5'">
                        <span class="font-bold text-foreground" :class="l.status === 'voided' ? 'line-through' : ''">{{ l.folio }}</span>
                        <span class="text-muted-foreground">{{ l.collaborator }} · {{ formatFriendlyDateTime(l.generated_at) }}</span>
                        <span v-if="l.status === 'voided'" class="rounded-full bg-destructive/10 px-2 py-0.5 text-[10px] font-bold text-destructive" :title="l.void_reason">Anulada · {{ l.void_reason }}</span>
                        <span v-else class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">Vigente{{ l.is_signed ? ' · firmada' : '' }}</span>
                        <span class="ml-auto flex gap-2">
                            <a :href="l.download_url" class="inline-flex items-center gap-1 font-bold text-primary hover:underline"><Download class="size-3.5" /> PDF</a>
                            <button v-if="canIssue && l.status !== 'voided'" type="button" class="inline-flex items-center gap-1 font-bold text-destructive hover:underline" @click="voidingId = voidingId === l.id ? null : l.id"><Ban class="size-3.5" /> Anular</button>
                        </span>
                        <div v-if="voidingId === l.id" class="w-full space-y-2 pt-2 animate-in fade-in">
                            <p class="text-[11px] text-muted-foreground">El PDF y el folio se conservan; la carta deja de ser vigente y podrás emitir otra con folio nuevo.<strong v-if="l.is_signed"> Esta carta tiene copia firmada.</strong></p>
                            <textarea v-model="voidReason" rows="2" maxlength="500" class="app-textarea text-xs" placeholder="Motivo de la anulación (obligatorio, mínimo 10 caracteres)" />
                            <div class="flex justify-end gap-2">
                                <Button variant="ghost" size="sm" class="rounded-lg" @click="voidingId = null">Cancelar</Button>
                                <Button size="sm" class="gap-1 rounded-lg bg-destructive text-white hover:bg-destructive/90" :disabled="voidReason.trim().length < 10 || voiding" @click="voidLetter(l)"><Loader2 v-if="voiding" class="size-3.5 animate-spin" /> Anular carta</Button>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
