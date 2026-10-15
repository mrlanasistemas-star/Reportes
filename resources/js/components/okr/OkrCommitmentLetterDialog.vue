<script setup lang="ts">
// Revisión de la Carta Compromiso (15-oct-2026). Muestra a la PERSONA
// EVALUADA (gestor del OKR individual o titular del OKR de sucursal — nunca
// quien creó el OKR), su puesto vigente con su fuente, la sugerencia de jefe
// inmediato (que se confirma o corrige), objetivo, KR, base, meta y periodo.
// Solo puesto (si el directorio no lo tiene), jefe inmediato y observaciones
// son capturables; identidad y metas congeladas no. Incluye el historial de
// cartas (vigente/anuladas) con anulación auditada.
import { router } from '@inertiajs/vue3'
import { AlertTriangle, Ban, CheckCircle2, Download, ExternalLink, Eye, FileSignature, Lightbulb, Loader2, UserCheck } from 'lucide-vue-next'
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
// Bug real corregido (15-oct-2026): cuando /commitment-letter/review fallaba
// (403/404/500/sin conexión), review.value quedaba en null y el template no
// tenía NINGÚN estado para eso — el diálogo se quedaba con el título y nada
// más, sin mensaje ni Reintentar. Ahora el error se guarda y se muestra.
const loadError = ref<string | null>(null)
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
    loadError.value = null
    const res = await okrRequest(`/okr/${props.objectiveId}/commitment-letter/review?${query()}`)

    if (mine !== version) {
        return
    }

    loading.value = false

    if (!res.ok) {
        review.value = null
        loadError.value = res.message

        return
    }

    review.value = res.data
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
        showInlinePreview.value = false
        viewingLetter.value = null
        issueBlock.value = null
        loadError.value = null
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

// Tres acciones independientes sobre la vista previa (sección 6 de la
// auditoría): previsualizar dentro del sistema, abrir en otra pestaña o
// descargar. La vista previa nunca emite documento ni folio definitivo.
const previewUrl = computed(() => `/okr/${props.objectiveId}/commitment-letter/preview?${query()}`)
const previewDownloadUrl = computed(() => `${previewUrl.value}&download=1`)
const showInlinePreview = ref(false)

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
// Bloqueo de emisión por datos faltantes (15-oct-2026) + "Completar información".
const BOSS_MISSING_MESSAGE = 'No es posible emitir la Carta Compromiso porque falta confirmar el jefe inmediato.'
const issueBlock = ref<string | null>(null)
const bossInput = ref<HTMLInputElement | null>(null)

function completeInformation() {
    confirmingIssue.value = false
    bossInput.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    bossInput.value?.focus()
}
const voidingId = ref<number | null>(null)
// Visor integrado (Fase 5): la carta EMITIDA o su copia firmada se ve aquí mismo.
const viewingLetter = ref<{ id: number; url: string; signed: boolean } | null>(null)
const voidReason = ref('')
const voiding = ref(false)

async function generate() {
    generating.value = true
    const res = await okrRequest(`/okr/${props.objectiveId}/commitment-letter`, { method: 'POST', json: { ...fields } })
    generating.value = false
    confirmingIssue.value = false

    if (!res.ok) {
        // Datos faltantes (p. ej. jefe inmediato sin confirmar): se explica
        // dentro del diálogo con la acción "Completar información", nunca
        // como un error genérico.
        if (res.data?.action === 'complete_information') {
            issueBlock.value = res.message
            await loadReview()
            completeInformation()

            return
        }

        Swal.fire({ icon: 'error', title: 'No se pudo emitir la carta', text: res.message })

        return
    }

    issueBlock.value = null
    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `Carta emitida · ${res.data.letter.folio}`, timer: 2600, showConfirmButton: false })
    await loadReview()
    // Se muestra de inmediato el documento EMITIDO (no la vista previa).
    showInlinePreview.value = false
    viewingLetter.value = { id: res.data.letter.id, url: res.data.letter.view_url, signed: false }
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

            <div v-else-if="loadError" class="space-y-2 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">
                <p>{{ loadError }}</p>
                <Button size="sm" variant="outline" class="rounded-lg" @click="loadReview">Reintentar</Button>
            </div>

            <div v-else-if="snap" class="space-y-4">
                <!-- Persona evaluada -->
                <section class="rounded-2xl border border-border p-3">
                    <p class="mb-2 flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-muted-foreground"><UserCheck class="size-3.5" /> {{ scopeType === 'branch' ? 'Titular del OKR de sucursal' : 'Colaborador evaluado' }}</p>
                    <template v-if="snap.collaborator_name">
                        <p class="text-base font-bold text-foreground">{{ snap.collaborator_name }} <span v-if="snap.employee_code" class="font-mono text-xs font-normal text-muted-foreground">· {{ snap.employee_code }}</span></p>
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
                        <input ref="bossInput" v-model="fields.immediate_boss" maxlength="160" class="app-input mt-1 h-9 text-sm" placeholder="Nombre del jefe inmediato real">
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
                            <span class="tabular-nums text-muted-foreground">{{ kr.baseline_label ?? 'Base' }} {{ kr.baseline_value === null ? 'se congela al activar' : formatByUnit(kr.baseline_value, kr.unit) }}<template v-if="kr.increment_value !== null && kr.increment_value !== undefined"> · Incremento {{ formatByUnit(kr.increment_value, kr.unit) }}</template> → <strong class="text-primary">Meta {{ formatByUnit(kr.target_value, kr.unit) }}</strong></span>
                        </div>
                        <p v-if="!snap.key_results.length" class="text-xs text-amber-700 dark:text-amber-300">Este OKR no tiene Key Results configurados.</p>
                    </div>
                    <textarea v-model="fields.observations" rows="2" maxlength="600" class="app-textarea mt-2 text-sm" placeholder="Observaciones (opcional, aparecen debajo de la tabla de KPI)" />
                </section>

                <div v-if="Object.keys(missing).length" class="flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-xs text-amber-800 dark:text-amber-200">
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    <div class="flex-1 space-y-1">
                        <p v-if="missing.immediate_boss || issueBlock" class="font-bold">{{ issueBlock ?? BOSS_MISSING_MESSAGE }}</p>
                        <p>Para emitir falta: <strong>{{ Object.values(missing).join(', ') }}</strong>. La vista previa sí está disponible (marca lo pendiente y no genera folio).</p>
                    </div>
                    <Button v-if="missing.immediate_boss" size="sm" variant="outline" class="shrink-0 rounded-lg border-amber-500/40" @click="completeInformation">Completar información</Button>
                </div>
                <div v-else class="flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                    <CheckCircle2 class="size-4" /> Datos completos.
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-2 text-xs font-semibold transition hover:-translate-y-0.5 hover:shadow-sm" :class="showInlinePreview ? 'border-primary/50 bg-primary/5 text-primary' : 'border-border text-foreground hover:border-primary/40'" @click="showInlinePreview = !showInlinePreview">
                        <Eye class="size-4" /> {{ showInlinePreview ? 'Ocultar vista previa' : 'Previsualizar aquí' }}
                    </button>
                    <a :href="previewUrl" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-border px-3 py-2 text-xs font-semibold text-foreground transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-sm"><ExternalLink class="size-4" /> Abrir en otra pestaña</a>
                    <a :href="previewDownloadUrl" class="inline-flex items-center gap-1.5 rounded-xl border border-border px-3 py-2 text-xs font-semibold text-foreground transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-sm"><Download class="size-4" /> Descargar PDF</a>
                </div>
                <div v-if="showInlinePreview" class="overflow-hidden rounded-xl border border-border" style="height: 70vh">
                    <iframe :src="previewUrl" class="h-full w-full" title="Vista previa de la Carta Compromiso" />
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span />
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
                        <span class="ml-auto flex flex-wrap gap-2">
                            <template v-if="l.file_available !== false">
                                <button type="button" class="inline-flex items-center gap-1 font-bold text-primary hover:underline" @click="viewingLetter = viewingLetter?.id === l.id && !viewingLetter?.signed ? null : { id: l.id, url: l.view_url, signed: false }"><Eye class="size-3.5" /> {{ viewingLetter?.id === l.id && !viewingLetter?.signed ? 'Ocultar' : 'Ver aquí' }}</button>
                                <a :href="l.view_url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-bold text-primary hover:underline"><ExternalLink class="size-3.5" /> Pestaña nueva</a>
                                <a :href="l.download_url" class="inline-flex items-center gap-1 font-bold text-primary hover:underline"><Download class="size-3.5" /> Descargar</a>
                            </template>
                            <span v-else class="font-semibold text-destructive">PDF no disponible en el almacenamiento — informa a Sistemas el folio {{ l.folio }}</span>
                            <button v-if="l.signed_view_url" type="button" class="inline-flex items-center gap-1 font-bold text-emerald-700 hover:underline dark:text-emerald-300" @click="viewingLetter = viewingLetter?.id === l.id && viewingLetter?.signed ? null : { id: l.id, url: l.signed_view_url, signed: true }"><FileSignature class="size-3.5" /> Ver firmada</button>
                            <button v-if="canIssue && l.status !== 'voided'" type="button" class="inline-flex items-center gap-1 font-bold text-destructive hover:underline" @click="voidingId = voidingId === l.id ? null : l.id"><Ban class="size-3.5" /> Anular</button>
                        </span>
                        <div v-if="viewingLetter?.id === l.id" class="w-full overflow-hidden rounded-xl border border-border bg-white animate-in fade-in" style="height: 68vh">
                            <iframe :src="viewingLetter?.url" class="h-full w-full" :title="`${l.folio}${viewingLetter?.signed ? ' firmada' : ''}`" />
                        </div>
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

            <!-- Nunca una ventana vacía (sección 8 del cierre, 15-oct-2026): si la
                 carga terminó sin error pero sin datos utilizables, se explica. -->
            <div v-else class="rounded-xl border border-border bg-muted/30 p-4 text-sm text-muted-foreground">
                No se pudo preparar la revisión de la Carta Compromiso para este OKR.
                <Button size="sm" variant="outline" class="ml-2 rounded-lg" @click="loadReview">Reintentar</Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
