<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { CheckCircle2, UserMinus } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { toRef } from 'vue'
import AppSectionCard from '@/components/app/AppSectionCard.vue'
import RosterListPager from '@/components/asignaciones/RosterListPager.vue'
import RosterListToolbar from '@/components/asignaciones/RosterListToolbar.vue'
import RosterMovementCard from '@/components/asignaciones/RosterMovementCard.vue'
import { useRosterList } from '@/components/asignaciones/useRosterList'
import type { RosterMovementItem } from '@/types/asignaciones'

const props = defineProps<{
    items: RosterMovementItem[]
    periodId: number | null
    canReconcile: boolean
}>()

const list = useRosterList(toRef(props, 'items'), 8)
const { query, category, branch, page } = list

type Decision = 'confirmar_baja' | 'descartar_baja'

// Conciliación autorizada: siempre con motivo (queda en la bitácora) y nunca en
// un solo clic — la confirmación explica qué cambia en Rotación.
async function review(item: RosterMovementItem, decision: Decision) {
    const key = item.identity_key ?? item.nombre_normalizado

    if (!props.periodId || !key) {
        return
    }

    const confirm = decision === 'confirmar_baja'
    const sources = (item.evidence ?? []).map((e) => `<li><strong>${escapeHtml(e.fuente)}:</strong> ${escapeHtml(e.detalle)}</li>`).join('')
    const result = await Swal.fire({
        title: confirm ? 'Confirmar baja' : 'Descartar como baja',
        html:
            (confirm
                ? `<p class="text-sm">${escapeHtml(item.employee_name)} contará como <strong>baja confirmada</strong> del periodo y entrará al índice de rotación.</p>`
                : `<p class="text-sm">${escapeHtml(item.employee_name)} <strong>no</strong> contará como baja (ausencia justificada, misma persona con otro nombre, etc.).</p>`) +
            (sources ? `<ul class="mt-3 space-y-1 text-left text-xs">${sources}</ul>` : ''),
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
        { nombre_normalizado: key, decision, note: String(result.value).trim() },
        { preserveScroll: true },
    )
}

function escapeHtml(value: string): string {
    return value.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c] as string)
}
</script>

<template>
    <AppSectionCard
        :title="`Por conciliar (${items.length})`"
        subtitle="Posibles bajas, ausencias sin evidencia, registros duplicados y fuentes que se contradicen. Ninguno cuenta como baja ni entra a la rotación hasta que un usuario autorizado lo confirme. Abre «Ver fuentes» para revisar la evidencia de cada caso."
        :padded="false"
    >
        <div class="space-y-2 p-4 sm:p-5">
            <RosterListToolbar
                v-model:query="query"
                v-model:category="category"
                v-model:branch="branch"
                placeholder="Buscar caso por nombre o sucursal..."
                :category-options="list.categoryOptions.value"
                :branch-options="list.branchOptions.value"
            />
            <p v-if="!canReconcile && items.length" class="text-xs text-muted-foreground">
                Tu rol puede consultar estos casos; para confirmarlos o descartarlos se necesita el permiso «Conciliar altas y bajas».
            </p>
        </div>

        <div class="space-y-3 border-t border-border p-4 sm:p-5">
            <RosterMovementCard
                v-for="item in list.paged.value"
                :key="`pend-${item.identity_key ?? item.nombre_normalizado}-${item.is_active ? 'a' : 'b'}`"
                :item="item"
                :branch-prefix="item.is_active ? 'Sucursal' : 'Última sucursal conocida'"
            >
                <template v-if="canReconcile && item.can_review" #actions>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-lg bg-rose-600 px-3 text-xs font-semibold text-white transition hover:bg-rose-500"
                        @click="review(item, 'confirmar_baja')"
                    >
                        <UserMinus class="size-3" />
                        Confirmar baja
                    </button>
                    <button
                        type="button"
                        class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border px-3 text-xs font-semibold transition hover:bg-muted"
                        @click="review(item, 'descartar_baja')"
                    >
                        <CheckCircle2 class="size-3" />
                        No es baja
                    </button>
                </template>
            </RosterMovementCard>
            <p v-if="!items.length" class="py-8 text-center text-sm text-muted-foreground">Sin casos por conciliar en este periodo.</p>
            <p v-else-if="!list.paged.value.length" class="py-8 text-center text-sm text-muted-foreground">Sin coincidencias con los filtros.</p>
        </div>
        <RosterListPager v-if="items.length" v-model="page" :total="list.filtered.value.length" :page-size="list.pageSize" :total-pages="list.totalPages.value" />
    </AppSectionCard>
</template>
