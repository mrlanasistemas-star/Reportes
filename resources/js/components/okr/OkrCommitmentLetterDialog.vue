<script setup lang="ts">
// Revisión previa de la Carta Compromiso (14-oct-2026): muestra exactamente
// los datos que llevará el PDF, marca los indispensables que faltan y deja
// capturar SOLO los campos autorizados (puesto, jefe inmediato, observaciones).
// La carta definitiva no se emite mientras falte un dato.
import { AlertTriangle, CheckCircle2, Eye, FileSignature, Loader2 } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, reactive, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { formatByUnit, formatFriendlyDate } from '@/lib/okrFormat'

const props = defineProps<{ objectiveId: number; canGenerate: boolean }>()
const emit = defineEmits<{ generated: [] }>()
const open = defineModel<boolean>('open', { default: false })

const fields = reactive({ position: '', immediate_boss: '', observations: '' })
const review = ref<any | null>(null)
const loading = ref(false)
const generating = ref(false)
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

    try {
        const res = await fetch(`/okr/${props.objectiveId}/commitment-letter/review?${query()}`, { cache: 'no-store', headers: { Accept: 'application/json' } })
        const data = res.ok ? await res.json() : null

        if (mine !== version) {
            return
        }

        review.value = data

        if (data && !fields.position && data.snapshot.position) {
            fields.position = data.snapshot.position
        }

        if (data && !fields.immediate_boss && data.snapshot.immediate_boss) {
            fields.immediate_boss = data.snapshot.immediate_boss
        }
    } finally {
        if (mine === version) {
            loading.value = false
        }
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        loadReview()
    }
})
watch(fields, () => {
    if (timer) {
        clearTimeout(timer)
    }

    timer = setTimeout(loadReview, 350)
})

const missing = computed<Record<string, string>>(() => review.value?.missing ?? {})
const snap = computed(() => review.value?.snapshot ?? null)
const rows = computed(() => snap.value ? [
    { label: 'Colaborador', value: snap.value.collaborator_name, key: 'collaborator_name' },
    { label: 'Sucursal / Área', value: snap.value.branch_name, key: 'branch_name' },
    { label: 'Fecha de inicio', value: formatFriendlyDate(snap.value.start_date), key: 'start_date' },
    { label: 'Fecha de término', value: formatFriendlyDate(snap.value.end_date), key: 'end_date' },
    { label: 'Lugar de emisión', value: snap.value.place, key: 'place' },
] : [])

function csrfFetch(url: string, init: RequestInit = {}) {
    const xsrfToken = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '')

    return fetch(url, { ...init, headers: { ...(init.headers ?? {}), 'X-XSRF-TOKEN': xsrfToken, Accept: 'application/json', 'Content-Type': 'application/json' } })
}

async function generate() {
    const confirm = await Swal.fire({
        icon: 'question', title: '¿Emitir la Carta Compromiso definitiva?',
        text: 'Se genera UNA sola vez con folio y queda congelada: si la meta cambia después, esta carta no se modifica.',
        showCancelButton: true, confirmButtonText: 'Sí, emitir', cancelButtonText: 'Revisar de nuevo', confirmButtonColor: '#059669',
    })

    if (!confirm.isConfirmed) {
        return
    }

    generating.value = true
    Swal.fire({ title: 'Generando PDF…', text: 'Preparando la carta con la tipografía y formato oficial.', allowOutsideClick: false, didOpen: () => Swal.showLoading() })

    try {
        const res = await csrfFetch(`/okr/${props.objectiveId}/commitment-letter`, { method: 'POST', body: JSON.stringify({ ...fields }) })
        const data = await res.json()

        if (!res.ok) {
            Swal.fire({ icon: 'error', title: 'No se pudo generar la Carta Compromiso', text: data.message ?? 'Error desconocido.' })

            return
        }

        Swal.fire({ icon: 'success', title: 'Carta emitida', text: `Folio ${data.letter.folio}`, timer: 2400, showConfirmButton: false })
        open.value = false
        emit('generated')
    } finally {
        generating.value = false
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
            <DialogTitle class="flex items-center gap-2 text-base font-bold"><FileSignature class="size-5 text-primary" /> Revisión de la Carta Compromiso</DialogTitle>

            <div v-if="!review && loading" class="space-y-2 py-4">
                <div v-for="n in 4" :key="n" class="h-9 animate-pulse rounded-lg bg-muted" />
            </div>

            <div v-else-if="snap" class="space-y-4">
                <div v-if="Object.keys(missing).length" class="flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-xs text-amber-800 dark:text-amber-200 animate-in fade-in">
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    <span>Faltan datos indispensables: <strong>{{ Object.values(missing).join(', ') }}</strong>. La carta definitiva se habilita al completarlos.</span>
                </div>
                <div v-else class="flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-3 text-xs font-semibold text-emerald-700 dark:text-emerald-300 animate-in fade-in">
                    <CheckCircle2 class="size-4" /> Todos los datos indispensables están completos.
                </div>

                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <div v-for="r in rows" :key="r.key" class="rounded-xl border p-2.5 transition hover:shadow-sm" :class="missing[r.key] ? 'border-amber-500/40 bg-amber-500/5' : 'border-border'">
                        <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{{ r.label }}</p>
                        <p class="text-sm font-semibold text-foreground">{{ r.value || 'Pendiente' }}</p>
                    </div>
                </div>

                <div class="rounded-xl border border-border p-3">
                    <p class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Objetivo principal</p>
                    <p class="text-sm font-semibold text-foreground">{{ snap.objective_title }}</p>
                    <div class="mt-2 space-y-1.5">
                        <div v-for="(kr, i) in snap.key_results" :key="i" class="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/40 px-2.5 py-1.5 text-xs">
                            <span class="font-semibold text-foreground">{{ kr.kpi_name }} <span class="font-normal text-muted-foreground">· {{ kr.weight }}%</span></span>
                            <span class="tabular-nums text-muted-foreground">Base {{ kr.baseline_value === null ? 'se congela al activar' : formatByUnit(kr.baseline_value, kr.unit) }} → <strong class="text-primary">Meta {{ formatByUnit(kr.target_value, kr.unit) }}</strong></span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label class="space-y-1">
                        <span class="text-xs font-semibold text-foreground">Puesto <span v-if="missing.position" class="text-amber-600">*</span></span>
                        <input v-model="fields.position" maxlength="120" class="app-input h-9 text-sm" :class="missing.position ? 'border-amber-500' : ''" placeholder="Ej. Gestor de crédito">
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs font-semibold text-foreground">Jefe inmediato <span v-if="missing.immediate_boss" class="text-amber-600">*</span></span>
                        <input v-model="fields.immediate_boss" maxlength="160" class="app-input h-9 text-sm" :class="missing.immediate_boss ? 'border-amber-500' : ''" placeholder="Nombre del jefe inmediato real">
                    </label>
                    <label class="space-y-1 sm:col-span-2">
                        <span class="text-xs font-semibold text-foreground">Observaciones (opcional)</span>
                        <textarea v-model="fields.observations" rows="2" maxlength="600" class="app-textarea text-sm" placeholder="Aparecen en la carta, debajo de la tabla de KPI" />
                    </label>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
                    <a :href="`/okr/${objectiveId}/commitment-letter/preview?${query()}`" target="_blank" class="inline-flex items-center gap-1.5 rounded-xl border border-border px-3 py-2 text-xs font-semibold text-foreground transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-sm">
                        <Eye class="size-4" /> Vista previa PDF
                    </a>
                    <Button
                        v-if="canGenerate" class="h-10 gap-1.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white shadow-lg shadow-emerald-600/30 transition hover:-translate-y-0.5"
                        :disabled="!review?.can_generate || generating || loading" @click="generate"
                    >
                        <Loader2 v-if="generating" class="size-4 animate-spin" /><FileSignature v-else class="size-4" /> Emitir carta definitiva
                    </Button>
                </div>
                <p v-if="!review?.is_activated" class="text-[11px] text-muted-foreground">La carta definitiva se habilita cuando el OKR esté activado (su línea base congelada).</p>
            </div>
        </DialogContent>
    </Dialog>
</template>
