<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { AlertTriangle, CheckCircle2, UserMinus } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import RosterMovementCard from '@/components/asignaciones/RosterMovementCard.vue'
import type { RosterMovementItem } from '@/types/asignaciones'

const props = defineProps<{
    items: RosterMovementItem[]
    periodId: number | null
    canReconcile: boolean
}>()

type Decision = 'confirmar_baja' | 'descartar_baja'

// Conciliación autorizada: siempre con motivo (queda en la bitácora) y nunca en
// un solo clic — la confirmación explica qué cambia en Rotación.
async function review(item: RosterMovementItem, decision: Decision) {
    if (!props.periodId || !item.nombre_normalizado) {
        return
    }

    const confirm = decision === 'confirmar_baja'
    const result = await Swal.fire({
        title: confirm ? 'Confirmar baja' : 'Descartar como baja',
        html: confirm
            ? `<p class="text-sm">${escapeHtml(item.employee_name)} contará como <strong>baja confirmada</strong> del periodo y entrará al índice de rotación.</p>`
            : `<p class="text-sm">${escapeHtml(item.employee_name)} <strong>no</strong> contará como baja (ausencia justificada, misma persona con otro nombre, etc.).</p>`,
        input: 'textarea',
        inputLabel: 'Motivo o evidencia (obligatorio)',
        inputPlaceholder: confirm ? 'Ej. RH confirma renuncia con fecha 30/09; finiquito pagado.' : 'Ej. Vacaciones del 1 al 15; sigue activo según RH.',
        inputValidator: (value) => (!value || value.trim().length < 5 ? 'Escribe al menos 5 caracteres.' : undefined),
        icon: confirm ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonText: confirm ? 'Sí, confirmar baja' : 'Sí, descartar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
        buttonsStyling: false,
        customClass: {
            popup: 'rounded-[28px] border border-border bg-card text-card-foreground shadow-2xl',
            title: 'text-xl font-bold text-foreground',
            htmlContainer: 'text-sm text-muted-foreground',
            actions: 'gap-3',
            confirmButton: 'app-btn app-btn-primary',
            cancelButton: 'app-btn app-btn-secondary',
        },
    })

    if (!result.isConfirmed) {
        return
    }

    router.post(
        `/asignaciones-empleado-sucursal/movimientos/${props.periodId}/conciliar`,
        { nombre_normalizado: item.nombre_normalizado, decision, note: String(result.value).trim() },
        { preserveScroll: true },
    )
}

function escapeHtml(value: string): string {
    return value.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string)
}
</script>

<template>
    <section class="app-card overflow-hidden">
        <div class="border-b px-4 py-4 sm:px-6 sm:py-5">
            <div class="flex items-center gap-2">
                <AlertTriangle class="size-4 text-amber-500" />
                <h2 class="text-lg font-bold tracking-tight">Pendientes de conciliación</h2>
                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">{{ items.length }}</span>
            </div>
            <p class="mt-1 text-sm text-muted-foreground">
                Posibles bajas, personas no localizadas, registros duplicados y fuentes que se contradicen. Ninguno cuenta como baja ni entra a la rotación
                hasta que un usuario autorizado lo confirme.
            </p>
            <p v-if="!canReconcile && items.length" class="mt-2 text-xs text-muted-foreground">
                Tu rol puede consultar estos casos; para confirmarlos o descartarlos se necesita el permiso «Conciliar altas y bajas».
            </p>
        </div>

        <div class="max-h-[30rem] space-y-3 overflow-y-auto p-4 sm:p-6">
            <RosterMovementCard
                v-for="item in items"
                :key="`pend-${item.nombre_normalizado}-${item.is_active ? 'a' : 'b'}`"
                :item="item"
                :branch-prefix="item.is_active ? 'Sucursal' : 'Última sucursal conocida'"
            >
                <template v-if="canReconcile && item.can_review" #actions>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-xl bg-rose-500 px-3 text-xs font-bold text-white transition duration-200 hover:-translate-y-0.5 hover:bg-rose-400"
                        @click="review(item, 'confirmar_baja')"
                    >
                        <UserMinus class="size-3" />
                        Confirmar baja
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-xl border border-border/70 px-3 text-xs font-bold transition duration-200 hover:-translate-y-0.5 hover:bg-muted"
                        @click="review(item, 'descartar_baja')"
                    >
                        <CheckCircle2 class="size-3" />
                        No es baja
                    </button>
                </template>
            </RosterMovementCard>
            <p v-if="!items.length" class="py-10 text-center text-sm text-muted-foreground">Sin pendientes de conciliación en este periodo.</p>
        </div>
    </section>
</template>
