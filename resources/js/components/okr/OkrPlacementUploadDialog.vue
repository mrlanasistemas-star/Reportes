<script setup lang="ts">
// Cargar / reemplazar la colocación de una semana (15-oct-2026). Un solo
// archivo de la sucursal alimenta a la sucursal y a cada gestor. Reemplazar
// exige confirmación explícita; el resultado detalla lo excluido.
import { router } from '@inertiajs/vue3'
import { FileSpreadsheet, Loader2, Upload } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { formatCurrency, formatFriendlyDate } from '@/lib/okrFormat'
import { okrRequest } from '@/lib/okrHttp'

const props = defineProps<{ objectiveId: number; scopeType: string; week: { week_number: number; week_start?: string; week_end?: string; uploaded?: boolean } | null; hasChildren: boolean }>()
const open = defineModel<boolean>('open', { default: false })

const file = ref<File | null>(null)
const completeReport = ref(true)
const uploading = ref(false)
const dragging = ref(false)
// Conflicto "ya hay archivo" — se confirma DENTRO del diálogo (un SweetAlert
// encima de un Dialog abierto no recibe clics).
const conflict = ref<{ original_filename: string; total_amount: number } | null>(null)

watch(open, (v) => {
    if (v) {
        file.value = null
        completeReport.value = true
        conflict.value = null
    }
})

function pick(e: Event) {
    file.value = (e.target as HTMLInputElement).files?.[0] ?? null
}

function drop(e: DragEvent) {
    dragging.value = false
    file.value = e.dataTransfer?.files?.[0] ?? null
}

async function submit(confirmReplace = false) {
    if (!props.week || !file.value) {
        return
    }

    const data = new FormData()
    data.append('week_number', String(props.week.week_number))
    data.append('file', file.value)

    if (confirmReplace) {
        data.append('confirm_replace', '1')
    }

    if (props.scopeType === 'branch' && completeReport.value) {
        data.append('complete_report', '1')
    }

    uploading.value = true
    const res = await okrRequest(`/okr/${props.objectiveId}/placement-uploads`, { method: 'POST', body: data })
    uploading.value = false

    if (res.status === 409) {
        conflict.value = res.data?.existing_upload ?? { original_filename: '', total_amount: 0 }

        return
    }

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se pudo cargar el archivo', text: res.message })

        return
    }

    const u = res.data.upload
    const notes = [
        u.rows_duplicated ? `${u.rows_duplicated} movimiento(s) ya contados en otra semana (no se suman dos veces)` : null,
        u.rows_other_branch ? `${u.rows_other_branch} fila(s) de otra sucursal excluidas` : null,
        u.rows_cancelled ? `${u.rows_cancelled} movimiento(s) cancelados o revertidos excluidos` : null,
        u.rows_out_of_objective_range ? `${u.rows_out_of_objective_range} fila(s) fuera del plazo del OKR excluidas` : null,
        u.unattributed_amount ? `${formatCurrency(u.unattributed_amount)} sin gestor identificado (solo cuenta para la sucursal)` : null,
    ].filter(Boolean)
    open.value = false
    Swal.fire({
        icon: 'success', title: res.data.replaced ? 'Colocación reemplazada' : 'Colocación cargada',
        html: `<p>Semana ${u.week_number}: <strong>${formatCurrency(u.total_amount)}</strong> en ${u.rows_count} movimiento(s).</p>`
            + (props.hasChildren ? '<p style="margin-top:6px;font-size:12px">Los OKR individuales de la sucursal se actualizaron con este mismo archivo.</p>' : '')
            + (notes.length ? `<ul style="margin-top:8px;text-align:left;font-size:12px">${notes.map((n) => `<li>• ${n}</li>`).join('')}</ul>` : ''),
    })
    router.reload({ only: ['objective', 'keyResults'] })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <DialogTitle class="flex items-center gap-2 text-base font-bold"><Upload class="size-5 text-primary" /> {{ week?.uploaded ? 'Reemplazar' : 'Cargar' }} colocación — Semana {{ week?.week_number }}</DialogTitle>
            <p v-if="week?.week_start" class="-mt-1 text-xs text-muted-foreground">Periodo: {{ formatFriendlyDate(week.week_start) }} al {{ formatFriendlyDate(week.week_end) }}</p>

            <label
                class="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed p-6 text-center transition"
                :class="dragging ? 'border-primary bg-primary/5' : file ? 'border-emerald-500/50 bg-emerald-500/5' : 'border-border hover:border-primary/50 hover:bg-muted/30'"
                @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="drop"
            >
                <FileSpreadsheet class="size-8" :class="file ? 'text-emerald-600' : 'text-muted-foreground'" />
                <span class="text-sm font-semibold text-foreground">{{ file ? file.name : 'Arrastra el reporte de colocación de Lendus o haz clic' }}</span>
                <span class="text-[11px] text-muted-foreground">Excel o CSV. Columnas: Gestor y Monto (Contrato, Fecha, Sucursal y Estatus opcionales).</span>
                <input type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="pick">
            </label>

            <label v-if="scopeType === 'branch'" class="flex cursor-pointer items-start gap-2 rounded-xl bg-muted/30 p-3 text-xs text-muted-foreground transition hover:bg-muted/50">
                <input v-model="completeReport" type="checkbox" class="mt-0.5 accent-primary">
                <span><strong class="text-foreground">Es el reporte completo de la sucursal en esa semana.</strong> Un gestor que no aparezca colocó $0.00. Si es un archivo parcial, desmárcalo: los gestores sin filas quedan "sin dato", nunca en cero.</span>
            </label>

            <div v-if="conflict" class="space-y-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3 text-xs animate-in fade-in">
                <p class="text-foreground">La semana {{ week?.week_number }} ya tiene el archivo <strong>{{ conflict.original_filename }}</strong> ({{ formatCurrency(conflict.total_amount) }}). El anterior se conserva como reemplazado.</p>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" size="sm" class="rounded-lg" :disabled="uploading" @click="conflict = null">No reemplazar</Button>
                    <Button size="sm" class="gap-1 rounded-lg bg-destructive text-white hover:bg-destructive/90" :disabled="uploading" @click="submit(true)"><Loader2 v-if="uploading" class="size-3.5 animate-spin" /> Sí, reemplazar</Button>
                </div>
            </div>

            <div v-else class="flex justify-end gap-2">
                <Button variant="ghost" class="rounded-xl" :disabled="uploading" @click="open = false">Cancelar</Button>
                <Button class="gap-1.5 rounded-xl" :disabled="!file || uploading" @click="submit()">
                    <Loader2 v-if="uploading" class="size-4 animate-spin" /><Upload v-else class="size-4" /> {{ uploading ? 'Procesando…' : (week?.uploaded ? 'Reemplazar archivo' : 'Cargar archivo') }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
