<script setup lang="ts">
// Bitácora del OKR — cada cambio de meta/peso/titular, activación, emisión o
// anulación de documentos, con su motivo. Valores con su unidad ($, %).
import { ScrollText } from 'lucide-vue-next'
import { formatByUnit, formatFriendlyDateTime } from '@/lib/okrFormat'

defineProps<{ logs: any[] }>()

const FIELD_LABELS: Record<string, string> = {
    weight: 'Ponderación modificada',
    target_value: 'Meta modificada',
    accountable_employee_id: 'Titular del OKR asignado',
}
const ACTION_LABELS: Record<string, string> = {
    created: 'OKR creado',
    activated: 'OKR activado',
    key_result_removed: 'Resultado clave eliminado',
    key_result_completed: 'Key Result configurado',
    baseline_manual_authorized: 'Línea base manual autorizada',
    commitment_letter_issued: 'Carta Compromiso emitida',
    commitment_letter_voided: 'Carta Compromiso anulada',
    warning_issued: 'Warning emitido',
    warning_voided: 'Warning anulado',
}

function label(log: any): string {
    if (log.action === 'field_changed' && FIELD_LABELS[log.field]) {
        return FIELD_LABELS[log.field]
    }

    return ACTION_LABELS[log.action] ?? (log.field ? `${log.field} modificado` : 'Actualización del OKR')
}

function value(log: any, v: any): string {
    if (v === null || v === undefined || v === '') {
        return '—'
    }

    if (['target_value', 'baseline_value'].includes(log.field)) {
        return formatByUnit(v, log.unit)
    }

    return log.field === 'weight' ? `${v}%` : String(v)
}
</script>

<template>
    <details v-if="logs.length" class="group rounded-2xl border border-border bg-card p-4 shadow-sm">
        <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-bold text-foreground">
            <ScrollText class="size-4 text-primary" /> Bitácora del OKR ({{ logs.length }})
            <span class="ml-auto text-xs font-normal text-muted-foreground group-open:hidden">Ver cambios</span>
        </summary>
        <ol class="mt-3 space-y-2">
            <li v-for="log in logs" :key="log.id" class="border-l-2 border-primary/30 pl-3 text-xs">
                <p class="font-semibold text-foreground">
                    {{ label(log) }}
                    <span v-if="log.old_value !== null || log.new_value !== null" class="font-normal text-muted-foreground">— {{ value(log, log.old_value) }} → {{ value(log, log.new_value) }}</span>
                </p>
                <p class="text-muted-foreground">{{ log.user ?? 'Sistema' }} · {{ formatFriendlyDateTime(log.created_at) }}<span v-if="log.reason"> · "{{ log.reason }}"</span></p>
            </li>
        </ol>
    </details>
</template>
