<script setup lang="ts">
// Documentos (8.10 §6, 19-oct-2026): Carta Compromiso, Warnings y copias
// firmadas de la PERSONA de este OKR; en un OKR de sucursal, además, los
// documentos de cada gestor (cada uno en su propio OKR — nunca se generan desde
// el objetivo general a nombre de otra persona). Cada documento se ve en el
// visor integrado, se abre en otra pestaña o se descarga; la copia firmada se
// sube y se consulta desde aquí.
import { router } from '@inertiajs/vue3'
import { Ban, CheckCircle2, Download, Eye, FileSignature, FileText, FileWarning, ShieldAlert, Upload } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { ref } from 'vue'
import type { OkrViewerDocument } from '@/components/okr/okrDocuments'
import OkrDocumentViewerDialog from '@/components/okr/OkrDocumentViewerDialog.vue'
import { avatarTone, initialsOf } from '@/components/okr/wizard/okrWizard'
import { Button } from '@/components/ui/button'
import { formatFriendlyDate, formatFriendlyDateTime } from '@/lib/okrFormat'
import { okrRequest } from '@/lib/okrHttp'

const props = defineProps<{
    objective: any
    warnings: any[]
    canAssign: boolean
    canUpdate: boolean
}>()
const emit = defineEmits<{ letter: []; warning: [] }>()

const viewerOpen = ref(false)
const viewerDoc = ref<OkrViewerDocument | null>(null)
const uploading = ref<string | null>(null)

function view(kind: 'letter' | 'warning', doc: any, signed = false) {
    viewerDoc.value = {
        kind, signed, folio: doc.folio, status: doc.status ?? 'issued', collaborator: doc.collaborator,
        date: doc.generated_at, detail: kind === 'warning' ? `Semana ${doc.week_number}` : null,
        view_url: signed ? doc.signed_view_url : doc.view_url, download_url: doc.download_url, file_available: doc.file_available,
    }
    viewerOpen.value = true
}

async function sendSigned(kind: 'letter' | 'warning', doc: any, file: File, replace: boolean): Promise<void> {
    const data = new FormData()
    data.append('file', file)

    if (replace) {
        data.append('replace', '1')
    }

    uploading.value = `${kind}-${doc.id}`
    const url = kind === 'letter' ? `/okr/commitment-letters/${doc.id}/signed` : `/okr/warnings/${doc.id}/signed`
    const res = await okrRequest(url, { method: 'POST', body: data })
    uploading.value = null

    if (res.status === 409 && res.data?.action === 'confirm_replace') {
        const r = await Swal.fire({ icon: 'question', title: 'Ya hay una copia firmada', text: res.ok ? '' : res.message, showCancelButton: true, confirmButtonText: 'Sustituir (la anterior se conserva en bitácora)', cancelButtonText: 'Cancelar' })

        if (r.isConfirmed) {
            await sendSigned(kind, doc, file, true)
        }

        return
    }

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se cargó el documento firmado', text: res.message })

        return
    }

    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: `Copia firmada de ${doc.folio} cargada`, timer: 2600, showConfirmButton: false })
    router.reload({ only: ['objective', 'warnings', 'auditLogs'] })
}

function uploadSigned(kind: 'letter' | 'warning', doc: any, e: Event) {
    const input = e.target as HTMLInputElement
    const file = input.files?.[0]
    input.value = ''

    if (file) {
        sendSigned(kind, doc, file, false)
    }
}

async function voidWarning(w: any) {
    const r = await Swal.fire({
        icon: 'warning', title: `Anular ${w.folio}`,
        html: 'El PDF y el folio se conservan; el Warning deja de estar vigente.' + (w.is_signed ? '<br><strong>Tiene copia firmada.</strong>' : ''),
        input: 'textarea', inputPlaceholder: 'Motivo (obligatorio, queda en bitácora)',
        inputValidator: (v) => (!v || v.trim().length < 10 ? 'Escribe un motivo de al menos 10 caracteres.' : undefined),
        showCancelButton: true, confirmButtonText: 'Anular', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc2626',
    })

    if (!r.isConfirmed) {
        return
    }

    const res = await okrRequest(`/okr/warnings/${w.id}/void`, { method: 'POST', json: { reason: r.value } })

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se anuló el Warning', text: res.message })

        return
    }

    router.reload({ only: ['warnings'] })
}

const subjectLabel = () => props.objective.subject?.full_name ?? (props.objective.scope_type === 'branch' ? 'el titular de la sucursal (sin asignar)' : 'el colaborador')
</script>

<template>
    <section class="rounded-2xl border border-border bg-card shadow-sm">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
            <div class="flex items-center gap-2">
                <span class="flex size-9 items-center justify-center rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-300"><FileText class="size-4" /></span>
                <div>
                    <p class="text-sm font-bold text-foreground">Documentos</p>
                    <p class="text-[11px] text-muted-foreground">Carta Compromiso, Warnings y firmas de {{ subjectLabel() }}.</p>
                </div>
            </div>
        </header>

        <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
            <!-- Carta Compromiso -->
            <div class="flex flex-col gap-2.5 rounded-xl border border-border p-3.5">
                <div class="flex items-center justify-between gap-2">
                    <p class="flex items-center gap-1.5 text-xs font-bold text-foreground"><FileSignature class="size-4 text-primary" /> Carta Compromiso</p>
                    <Button size="sm" data-tour="okr-letter-button" class="h-8 rounded-lg text-xs" @click="emit('letter')">{{ objective.commitment_letter ? 'Gestionar carta' : 'Revisar carta' }}</Button>
                </div>

                <template v-if="objective.commitment_letter">
                    <div class="rounded-lg bg-emerald-500/5 px-3 py-2.5 text-xs ring-1 ring-emerald-500/20">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span class="font-bold text-foreground">{{ objective.commitment_letter.folio }}</span>
                            <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">Vigente</span>
                            <span v-if="objective.commitment_letter.is_signed" class="inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold text-white"><CheckCircle2 class="size-3" /> Firmada</span>
                            <span v-else class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-300">Pendiente de firma</span>
                        </div>
                        <p class="mt-1 text-muted-foreground">{{ objective.commitment_letter.collaborator }} · emitida el {{ formatFriendlyDate(objective.commitment_letter.generated_at?.slice(0, 10)) }}<template v-if="objective.commitment_letter.immediate_boss"> · Jefe inmediato: {{ objective.commitment_letter.immediate_boss }}</template></p>
                        <p v-if="objective.commitment_letter.file_available === false" class="mt-1.5 flex items-center gap-1 font-semibold text-destructive"><FileWarning class="size-3.5" /> El PDF no está en el almacenamiento. Informa a Sistemas el folio; no vuelvas a emitirla.</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <Button v-if="objective.commitment_letter.file_available !== false" size="sm" variant="outline" class="h-8 gap-1 rounded-lg text-xs" @click="view('letter', objective.commitment_letter)"><Eye class="size-3.5" /> Ver documento</Button>
                            <a v-if="objective.commitment_letter.file_available !== false" :href="objective.commitment_letter.download_url" class="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-bold text-primary hover:bg-primary/5"><Download class="size-3.5" /> Descargar</a>
                            <Button v-if="objective.commitment_letter.is_signed" size="sm" variant="outline" class="h-8 gap-1 rounded-lg text-xs" @click="view('letter', objective.commitment_letter, true)"><FileSignature class="size-3.5" /> Ver firmada</Button>
                            <label v-if="canUpdate" class="inline-flex h-8 cursor-pointer items-center gap-1 rounded-lg px-2 text-xs font-bold text-primary hover:bg-primary/5" :class="uploading === `letter-${objective.commitment_letter.id}` ? 'pointer-events-none opacity-60' : ''">
                                <Upload class="size-3.5" /> {{ uploading === `letter-${objective.commitment_letter.id}` ? 'Subiendo…' : (objective.commitment_letter.is_signed ? 'Sustituir firmada' : 'Subir firmada') }}
                                <input type="file" accept="application/pdf,.pdf" class="hidden" @change="(e) => uploadSigned('letter', objective.commitment_letter, e)">
                            </label>
                        </div>
                    </div>
                </template>
                <p v-else class="rounded-lg border border-dashed border-border px-3 py-3 text-xs text-muted-foreground">
                    {{ objective.lifecycle_status === 'draft' ? 'La carta definitiva se emite al activar el OKR. Ya puedes revisar la vista previa.' : 'Todavía no hay carta vigente. Revísala y emítela para formalizar el compromiso.' }}
                </p>
                <p v-if="(objective.commitment_letters ?? []).some((l: any) => l.status === 'voided')" class="text-[11px] text-muted-foreground">{{ objective.commitment_letters.filter((l: any) => l.status === 'voided').length }} carta(s) anulada(s) en el historial (folio, PDF y motivo conservados).</p>
            </div>

            <!-- Warnings -->
            <div class="flex flex-col gap-2.5 rounded-xl border border-border p-3.5">
                <div class="flex items-center justify-between gap-2">
                    <p class="flex items-center gap-1.5 text-xs font-bold text-foreground"><ShieldAlert class="size-4 text-rose-600" /> Warnings</p>
                    <Button size="sm" variant="outline" data-tour="okr-warning-button" class="h-8 rounded-lg text-xs" @click="emit('warning')">Evaluar Warning</Button>
                </div>
                <div v-for="w in warnings" :key="w.id" class="rounded-lg px-3 py-2.5 text-xs ring-1" :class="w.status === 'voided' ? 'bg-muted/40 opacity-80 ring-border' : 'bg-rose-500/5 ring-rose-500/20'">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-bold text-foreground" :class="w.status === 'voided' ? 'line-through' : ''">{{ w.folio }}</span>
                        <span class="text-muted-foreground">Semana {{ w.week_number }} · {{ w.collaborator }} · {{ formatFriendlyDate(w.generated_at?.slice(0, 10)) }}</span>
                        <span v-if="w.status === 'voided'" class="rounded-full bg-destructive/10 px-2 py-0.5 text-[10px] font-bold text-destructive" :title="w.void_reason">Anulado</span>
                        <span v-else-if="w.is_signed" class="inline-flex items-center gap-1 rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-bold text-white"><CheckCircle2 class="size-3" /> Firmado</span>
                        <span v-else class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-300">Pendiente de firma</span>
                    </div>
                    <p v-if="w.status === 'voided' && w.void_reason" class="mt-1 text-[11px] text-muted-foreground">Motivo de anulación: {{ w.void_reason }}</p>
                    <p v-if="w.file_available === false" class="mt-1 flex items-center gap-1 font-semibold text-destructive"><FileWarning class="size-3.5" /> PDF no disponible en el almacenamiento.</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <Button v-if="w.file_available !== false" size="sm" variant="outline" class="h-8 gap-1 rounded-lg text-xs" @click="view('warning', w)"><Eye class="size-3.5" /> Ver documento</Button>
                        <a v-if="w.file_available !== false" :href="w.download_url" class="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-bold text-primary hover:bg-primary/5"><Download class="size-3.5" /> Descargar</a>
                        <Button v-if="w.is_signed" size="sm" variant="outline" class="h-8 gap-1 rounded-lg text-xs" @click="view('warning', w, true)"><FileSignature class="size-3.5" /> Ver firmado</Button>
                        <template v-if="w.status !== 'voided'">
                            <label v-if="canUpdate" class="inline-flex h-8 cursor-pointer items-center gap-1 rounded-lg px-2 text-xs font-bold text-primary hover:bg-primary/5" :class="uploading === `warning-${w.id}` ? 'pointer-events-none opacity-60' : ''">
                                <Upload class="size-3.5" /> {{ uploading === `warning-${w.id}` ? 'Subiendo…' : (w.is_signed ? 'Sustituir firmado' : 'Subir firmado') }}
                                <input type="file" accept="application/pdf,.pdf" class="hidden" @change="(e) => uploadSigned('warning', w, e)">
                            </label>
                            <button v-if="canAssign" type="button" class="inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-bold text-destructive hover:bg-destructive/5" @click="voidWarning(w)"><Ban class="size-3.5" /> Anular</button>
                        </template>
                    </div>
                </div>
                <p v-if="!warnings.length" class="rounded-lg border border-dashed border-border px-3 py-3 text-xs text-muted-foreground">Sin Warnings emitidos. Solo proceden con OKR activo, semana cerrada, datos suficientes, incumplimiento real y Carta Compromiso vigente.</p>
            </div>
        </div>

        <!-- Documentos por gestor -->
        <div v-if="(objective.gestor_tracking ?? []).length" class="border-t border-border p-4">
            <p class="mb-2 text-xs font-bold text-foreground">Documentos por gestor</p>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
                <button v-for="g in objective.gestor_tracking" :key="g.id" type="button" class="group flex items-center gap-2.5 rounded-xl border border-border p-2.5 text-left text-xs transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md" @click="router.get(`${g.url}?panel=documents`)">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-[10px] font-bold text-white" :class="avatarTone(g.id)">{{ initialsOf(g.employee) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-foreground">{{ g.employee }}</span>
                        <span class="block text-[11px] text-muted-foreground">{{ g.letter ? `Carta ${g.letter.folio}${g.letter.is_signed ? ' · firmada' : ' · sin firma'}` : 'Sin carta' }} · {{ g.warnings_count }} warning(s)</span>
                    </span>
                    <FileSignature class="size-4 text-muted-foreground transition group-hover:text-primary" />
                </button>
            </div>
        </div>

        <p v-if="objective.activated_at" class="px-4 pb-3 text-[10px] text-muted-foreground">OKR activado el {{ formatFriendlyDateTime(objective.activated_at) }}.</p>

        <OkrDocumentViewerDialog v-model:open="viewerOpen" :document="viewerDoc" />
    </section>
</template>
