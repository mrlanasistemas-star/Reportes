<script setup lang="ts">
import { AlertTriangle, ArrowLeftRight, Building2, RotateCcw, UserMinus, UserPlus, UserRound } from 'lucide-vue-next'
import { computed } from 'vue'
import type { LaborData } from '@/types/asignaciones'

const props = defineProps<{
    labor: LaborData
}>()

// Cada cifra cuenta EXACTAMENTE lo que dice su etiqueta (personas del roster
// laboral, nunca registros de asignación de sucursal).
const stats = computed(() => {
    const c = props.labor.counts
    const r = props.labor.roster

    return [
        { icon: UserRound, label: 'Plantilla NOI', value: r.plantilla, tone: 'text-foreground', title: 'Personas distintas (sin duplicados) en la nómina NOI normal o fiscal del periodo.' },
        { icon: Building2, label: 'Con sucursal', value: r.con_sucursal ?? r.plantilla, tone: 'text-foreground', title: `De las ${r.plantilla} personas de la plantilla, cuántas tienen sucursal resuelta (${r.sin_sucursal ?? 0} sin sucursal).` },
        { icon: UserPlus, label: 'Altas confirmadas', value: c.altas_confirmadas ?? props.labor.movements.altas.length, tone: 'text-emerald-600 dark:text-emerald-400', title: 'Primera vez en la nómina NOI de este periodo, sin registro anterior.' },
        { icon: RotateCcw, label: 'Reingresos', value: c.reingresos ?? props.labor.movements.reingresos.length, tone: 'text-emerald-600 dark:text-emerald-400', title: 'Vuelven a nómina después de una baja confirmada.' },
        { icon: ArrowLeftRight, label: 'Cambios de sucursal', value: c.cambios_sucursal ?? props.labor.movements.cambios_sucursal.length, tone: 'text-sky-600 dark:text-sky-400', title: 'Siguen en nómina con otra sucursal respecto al periodo anterior. No son alta ni baja.' },
        { icon: UserMinus, label: 'Bajas confirmadas', value: c.bajas_confirmadas, tone: 'text-rose-600 dark:text-rose-400', title: 'Solo con evidencia válida para el periodo: directorio laboral INACTIVO, finiquito en NOI o conciliación autorizada. Son las que cuentan en la rotación.' },
        { icon: AlertTriangle, label: 'Por conciliar', value: c.pendientes, tone: 'text-amber-600 dark:text-amber-400', title: 'Posibles bajas, ausencias, duplicados y contradicciones de fuentes. No cuentan como bajas.' },
    ]
})
</script>

<template>
    <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-7">
        <div v-for="stat in stats" :key="stat.label" :title="stat.title" class="rounded-xl border border-border bg-card px-3 py-2.5 shadow-soft-xs">
            <dt class="flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                <component :is="stat.icon" class="size-3.5 shrink-0" />
                <span class="truncate">{{ stat.label }}</span>
            </dt>
            <dd class="mt-1 text-xl font-bold tabular-nums" :class="stat.tone">{{ stat.value }}</dd>
        </div>
    </dl>
</template>
