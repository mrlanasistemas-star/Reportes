<script setup lang="ts">
// Barra de progreso reutilizable — cumplimiento de KR/Objective y ponderación
// del wizard. Verde=cumple/100%, ámbar=en curso/falta, rojo=excede/crítico.
import { computed } from 'vue'

const props = withDefaults(defineProps<{
    value: number
    max?: number
    tone?: 'auto' | 'success' | 'warning' | 'danger'
    showLabel?: boolean
}>(), {
    max: 100,
    tone: 'auto',
    showLabel: false,
})

const pct = computed(() => Math.max(0, Math.min(100, (props.value / props.max) * 100)))

const barClass = computed(() => {
    if (props.tone !== 'auto') {
        return { success: 'bg-emerald-500', warning: 'bg-amber-500', danger: 'bg-rose-500' }[props.tone]
    }
    if (props.value >= props.max) return 'bg-emerald-500'
    if (props.value >= props.max * 0.6) return 'bg-primary'
    return 'bg-amber-500'
})
</script>

<template>
    <div class="flex w-full items-center gap-2">
        <div class="h-2 w-full min-w-16 overflow-hidden rounded-full bg-muted">
            <div
                class="h-full rounded-full transition-all duration-500 ease-out"
                :class="barClass"
                :style="{ width: `${pct}%` }"
            />
        </div>
        <span v-if="showLabel" class="shrink-0 text-xs font-semibold tabular-nums text-muted-foreground">
            {{ Math.round(pct) }}%
        </span>
    </div>
</template>
