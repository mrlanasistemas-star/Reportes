<script setup lang="ts">
// "Por qué" del semáforo (sección 26 del pedido) — nunca solo un badge sin
// explicación. Abre un popover con el desglose real detrás de la clasificación.
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import { formatPercentage, formatPp } from '@/lib/okrFormat'

const props = defineProps<{
    health: string | null
    expectedProgress: number | null
    actualProgress: number | null
    deviationPp: number | null
    weeksLeft: number
    projectedCompliance: number | null
}>()

const reasonLabel: Record<string, string> = {
    ahead: 'Adelantado', on_track: 'En trayectoria', risk: 'En riesgo', off_track: 'Fuera de trayectoria',
}
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button type="button" class="inline-flex cursor-help items-center">
                <OkrStatusBadge kind="health" :value="health" />
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-72 space-y-2 text-xs" align="start">
            <p class="text-sm font-bold text-foreground">{{ health ? reasonLabel[health] ?? health : 'Sin evaluar' }} porque:</p>
            <ul class="space-y-1 text-muted-foreground">
                <li>Esperado: <span class="font-semibold text-foreground">{{ formatPercentage(expectedProgress) }}</span></li>
                <li>Real: <span class="font-semibold text-foreground">{{ formatPercentage(actualProgress) }}</span></li>
                <li>Desviación: <span class="font-semibold text-foreground">{{ formatPp(deviationPp) }}</span></li>
                <li>Quedan: <span class="font-semibold text-foreground">{{ weeksLeft }} semana(s)</span></li>
                <li>Proyección de cierre: <span class="font-semibold text-foreground">{{ projectedCompliance === null ? '—' : formatPercentage(projectedCompliance) }}</span></li>
            </ul>
        </PopoverContent>
    </Popover>
</template>
