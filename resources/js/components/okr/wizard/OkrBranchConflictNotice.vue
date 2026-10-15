<script setup lang="ts">
// Prioridad 5 (15-oct-2026) — aviso de sucursal en conflicto: dos fuentes del
// MISMO periodo ubican al colaborador en sucursales distintas. No se elige
// ninguna en automático; un administrador concilia eligiendo una de las
// sucursales que reportan las fuentes (nunca otra).
import { AlertTriangle, Check } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { ref } from 'vue'
import { okrRequest } from '@/lib/okrHttp'

export type BranchConflict = {
    message: string
    detail: string
    evidence_date: string
    branch_ids: number[]
    sources: { source: string; label: string; branch_id: number; branch_name: string | null; route: string | null }[]
}

const props = defineProps<{ conflict: BranchConflict; employeeId: number; employeeName: string; canReconcile: boolean }>()
const emit = defineEmits<{ reconciled: [] }>()

const saving = ref(false)

function esc(value: string | null): string {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string)
}

async function choose(branchId: number, branchName: string | null, label: string) {
    const confirm = await Swal.fire({
        icon: 'question',
        title: 'Conciliar sucursal',
        html: `¿Confirmas que <b>${esc(props.employeeName)}</b> pertenece a <b>${esc(branchName ?? 'esta sucursal')}</b>?<br><span style="font-size:12px">Fuente elegida: ${esc(label)}. Queda registrado quién lo autorizó; no modifica Reportería.</span>`,
        input: 'text',
        inputPlaceholder: 'Nota opcional (p. ej. confirmado con RH)',
        showCancelButton: true,
        confirmButtonText: 'Sí, conciliar',
        cancelButtonText: 'Cancelar',
    })

    if (!confirm.isConfirmed) {
        return
    }

    saving.value = true
    const res = await okrRequest('/okr/branch-reconciliations', {
        method: 'POST',
        json: { employee_id: props.employeeId, branch_id: branchId, notes: confirm.value || null },
    })
    saving.value = false

    if (!res.ok) {
        Swal.fire({ icon: 'error', title: 'No se concilió la sucursal', text: res.message })

        return
    }

    Swal.fire({ icon: 'success', title: 'Sucursal conciliada', text: res.data.message, timer: 2500, showConfirmButton: false })
    emit('reconciled')
}
</script>

<template>
    <div class="rounded-lg border border-amber-300/60 bg-amber-50 p-2 text-[10px] text-amber-900 dark:border-amber-500/40 dark:bg-amber-950/40 dark:text-amber-100">
        <p class="flex items-start gap-1 font-semibold"><AlertTriangle class="mt-0.5 size-3 shrink-0" /> {{ conflict.message }}</p>
        <ul class="mt-1 space-y-0.5 pl-4">
            <li v-for="s in conflict.sources" :key="s.source + s.branch_id" class="flex flex-wrap items-center justify-between gap-1">
                <span>{{ s.label }}: <b>{{ s.branch_name ?? 'sucursal desconocida' }}</b></span>
                <button
                    v-if="canReconcile" type="button" :disabled="saving"
                    class="inline-flex items-center gap-0.5 rounded-md border border-amber-400/70 px-1.5 py-0.5 font-semibold transition hover:bg-amber-100 disabled:opacity-50 dark:hover:bg-amber-900/50"
                    @click="choose(s.branch_id, s.branch_name, s.label)"
                >
                    <Check class="size-3" /> Usar esta
                </button>
            </li>
        </ul>
        <p class="mt-1 text-amber-800/80 dark:text-amber-200/80">
            {{ canReconcile ? 'Elige la sucursal correcta para poder asignarle un OKR.' : 'Un administrador debe conciliar su sucursal antes de asignarle un OKR.' }}
        </p>
    </div>
</template>
