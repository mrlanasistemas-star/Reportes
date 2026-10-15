<script setup lang="ts">
// Documentos y compromisos (15-oct-2026): Carta Compromiso, Warnings,
// evidencias y check-ins de la PERSONA de este OKR; en un OKR de sucursal,
// además, los documentos de cada gestor (cada uno en su propio OKR — nunca se
// generan desde el objetivo general a nombre de otra persona).
import { router } from '@inertiajs/vue3'
import { Ban, ClipboardCheck, Download, FileSignature, FileText, ShieldAlert, Upload } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { avatarTone, initialsOf } from '@/components/okr/wizard/okrWizard'
import { Button } from '@/components/ui/button'
import { formatFriendlyDate, formatFriendlyDateTime } from '@/lib/okrFormat'
import { okrRequest } from '@/lib/okrHttp'

defineProps<{
    objective: any
    warnings: any[]
    evidences: any[]
    checkIns: any[]
    canAssign: boolean
    canUpdate: boolean
    canUploadEvidence: boolean
    canCheckin: boolean
}>()
const emit = defineEmits<{ letter: []; warning: []; evidence: []; checkin: [] }>()

async function uploadSigned(kind: 'letter' | 'warning', id: number, e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0]

    if (!file) {
        return
    }

    const data = new FormData()
    data.append('file', file)
    const url = kind === 'letter' ? `/okr/commitment-letters/${id}/signed` : `/okr/warnings/${id}/signed`
    const res = await okrRequest(url, { method: 'POST', body: data })

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se cargó el documento firmado', text: res.message })

        return
    }

    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Documento firmado cargado', timer: 2200, showConfirmButton: false })
    router.reload({ only: ['objective', 'warnings'] })
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
</script>

<template>
    <section class="rounded-2xl border border-border bg-card shadow-sm">
        <header class="flex items-center gap-2 border-b border-border px-4 py-3">
            <span class="flex size-9 items-center justify-center rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-300"><FileText class="size-4" /></span>
            <div>
                <p class="text-sm font-bold text-foreground">Documentos y compromisos</p>
                <p class="text-[11px] text-muted-foreground">De {{ objective.subject?.full_name ?? (objective.scope_type === 'branch' ? 'el titular de la sucursal (sin asignar)' : 'el colaborador') }}.</p>
            </div>
        </header>

        <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
            <!-- Carta -->
            <div class="space-y-2 rounded-xl border border-border p-3 transition hover:shadow-md">
                <div class="flex items-center justify-between">
                    <p class="flex items-center gap-1.5 text-xs font-bold text-foreground"><FileSignature class="size-4 text-primary" /> Carta Compromiso</p>
                    <Button size="sm" class="h-8 rounded-lg text-xs" @click="emit('letter')">{{ objective.commitment_letter ? 'Ver / gestionar' : 'Revisar y emitir' }}</Button>
                </div>
                <div v-if="objective.commitment_letter" class="flex flex-wrap items-center gap-2 rounded-lg bg-emerald-500/5 px-2.5 py-2 text-xs">
                    <span class="font-bold text-foreground">{{ objective.commitment_letter.folio }}</span>
                    <span class="text-muted-foreground">{{ objective.commitment_letter.collaborator }} · {{ formatFriendlyDate(objective.commitment_letter.generated_at?.slice(0, 10)) }}</span>
                    <span class="ml-auto flex items-center gap-2">
                        <a :href="objective.commitment_letter.download_url" class="inline-flex items-center gap-1 font-bold text-primary hover:underline"><Download class="size-3.5" /> PDF</a>
                        <span v-if="objective.commitment_letter.is_signed" class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">Firmada</span>
                        <label v-else-if="canUpdate" class="cursor-pointer font-bold text-primary hover:underline">Subir firmada<input type="file" accept=".pdf" class="hidden" @change="(e) => uploadSigned('letter', objective.commitment_letter.id, e)"></label>
                    </span>
                </div>
                <p v-else class="text-xs text-muted-foreground">Sin carta vigente.</p>
                <p v-if="(objective.commitment_letters ?? []).some((l: any) => l.status === 'voided')" class="text-[11px] text-muted-foreground">{{ objective.commitment_letters.filter((l: any) => l.status === 'voided').length }} carta(s) anulada(s) en el historial.</p>
            </div>

            <!-- Warnings -->
            <div class="space-y-2 rounded-xl border border-border p-3 transition hover:shadow-md">
                <div class="flex items-center justify-between">
                    <p class="flex items-center gap-1.5 text-xs font-bold text-foreground"><ShieldAlert class="size-4 text-rose-600" /> Warnings</p>
                    <Button size="sm" variant="outline" class="h-8 rounded-lg text-xs" @click="emit('warning')">Evaluar incumplimiento</Button>
                </div>
                <div v-for="w in warnings" :key="w.id" class="flex flex-wrap items-center gap-2 rounded-lg px-2.5 py-2 text-xs" :class="w.status === 'voided' ? 'bg-muted/40 opacity-75' : 'bg-rose-500/5'">
                    <span class="font-bold text-foreground" :class="w.status === 'voided' ? 'line-through' : ''">{{ w.folio }}</span>
                    <span class="text-muted-foreground">S{{ w.week_number }} · {{ w.collaborator }}</span>
                    <span v-if="w.status === 'voided'" class="rounded-full bg-destructive/10 px-2 py-0.5 text-[10px] font-bold text-destructive" :title="w.void_reason">Anulado</span>
                    <span class="ml-auto flex items-center gap-2">
                        <a :href="w.download_url" class="inline-flex items-center gap-1 font-bold text-primary hover:underline"><Download class="size-3.5" /> PDF</a>
                        <template v-if="w.status !== 'voided'">
                            <span v-if="w.is_signed" class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300">Firmado</span>
                            <label v-else-if="canUpdate" class="cursor-pointer font-bold text-primary hover:underline">Subir firmado<input type="file" accept=".pdf" class="hidden" @change="(e) => uploadSigned('warning', w.id, e)"></label>
                            <button v-if="canAssign" type="button" class="inline-flex items-center gap-0.5 font-bold text-destructive hover:underline" @click="voidWarning(w)"><Ban class="size-3.5" /> Anular</button>
                        </template>
                    </span>
                </div>
                <p v-if="!warnings.length" class="text-xs text-muted-foreground">Sin warnings emitidos. Solo se habilitan con una semana cerrada e incumplimiento real.</p>
            </div>

            <!-- Evidencias -->
            <div class="space-y-2 rounded-xl border border-border p-3 transition hover:shadow-md">
                <div class="flex items-center justify-between">
                    <p class="flex items-center gap-1.5 text-xs font-bold text-foreground"><FileText class="size-4 text-sky-600" /> Evidencias</p>
                    <Button v-if="canUploadEvidence" size="sm" variant="outline" class="h-8 gap-1 rounded-lg text-xs" @click="emit('evidence')"><Upload class="size-3.5" /> Subir</Button>
                </div>
                <a v-for="e in evidences.slice(0, 5)" :key="e.id" :href="`/okr/evidences/${e.id}/download`" class="flex items-center justify-between rounded-lg px-2.5 py-1.5 text-xs transition hover:bg-muted/50">
                    <span class="truncate font-semibold text-foreground">{{ e.original_name }}</span>
                    <span class="shrink-0 text-[11px] text-muted-foreground">{{ e.uploader }} · {{ formatFriendlyDate(e.created_at?.slice(0, 10)) }}</span>
                </a>
                <p v-if="!evidences.length" class="text-xs text-muted-foreground">Sin evidencias todavía.</p>
            </div>

            <!-- Check-ins -->
            <div class="space-y-2 rounded-xl border border-border p-3 transition hover:shadow-md">
                <div class="flex items-center justify-between">
                    <p class="flex items-center gap-1.5 text-xs font-bold text-foreground"><ClipboardCheck class="size-4 text-emerald-600" /> Check-ins</p>
                    <Button v-if="canCheckin && objective.lifecycle_status === 'active'" size="sm" variant="outline" class="h-8 rounded-lg text-xs" @click="emit('checkin')">Registrar</Button>
                </div>
                <div v-for="c in checkIns.slice(0, 4)" :key="c.id" class="rounded-lg px-2.5 py-1.5 text-xs transition hover:bg-muted/50">
                    <p class="font-semibold text-foreground">Semana {{ c.week_number }} — {{ c.user }}</p>
                    <p v-if="c.main_blocker" class="text-muted-foreground">Bloqueo: {{ c.main_blocker }}</p>
                </div>
                <p v-if="!checkIns.length" class="text-xs text-muted-foreground">Sin check-ins todavía.</p>
            </div>
        </div>

        <!-- Documentos por gestor -->
        <div v-if="(objective.gestor_tracking ?? []).length" class="border-t border-border p-4">
            <p class="mb-2 text-xs font-bold text-foreground">Documentos por gestor</p>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
                <button v-for="g in objective.gestor_tracking" :key="g.id" type="button" class="group flex items-center gap-2.5 rounded-xl border border-border p-2.5 text-left text-xs transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md" @click="router.get(`${g.url}?panel=letter`)">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-[10px] font-bold text-white" :class="avatarTone(g.id)">{{ initialsOf(g.employee) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold text-foreground">{{ g.employee }}</span>
                        <span class="block text-[11px] text-muted-foreground">{{ g.letter ? `Carta ${g.letter.folio}${g.letter.is_signed ? ' · firmada' : ''}` : 'Sin carta' }} · {{ g.warnings_count }} warning(s)</span>
                    </span>
                    <FileSignature class="size-4 text-muted-foreground transition group-hover:text-primary" />
                </button>
            </div>
        </div>

        <p v-if="objective.activated_at" class="px-4 pb-3 text-[10px] text-muted-foreground">OKR activado el {{ formatFriendlyDateTime(objective.activated_at) }}.</p>
    </section>
</template>
