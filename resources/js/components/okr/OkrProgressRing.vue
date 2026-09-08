<script setup lang="ts">
// Anillo de progreso grande del hero (sección 18 del pedido) — SVG inline,
// sin dependencia nueva.
import { computed } from 'vue'

const props = withDefaults(defineProps<{ value: number; size?: number; label?: string }>(), {
    size: 120,
})

const pct = computed(() => Math.max(0, Math.min(100, props.value)))
const radius = computed(() => props.size / 2 - 10)
const circumference = computed(() => 2 * Math.PI * radius.value)
const offset = computed(() => circumference.value * (1 - pct.value / 100))
const color = computed(() => (pct.value >= 90 ? '#10b981' : pct.value >= 60 ? '#4f46e5' : pct.value >= 30 ? '#f59e0b' : '#f43f5e'))
</script>

<template>
    <div class="relative inline-flex items-center justify-center" :style="{ width: `${size}px`, height: `${size}px` }">
        <svg :width="size" :height="size" class="-rotate-90">
            <circle :cx="size / 2" :cy="size / 2" :r="radius" fill="none" stroke="currentColor" class="text-muted/40" stroke-width="10" />
            <circle
                :cx="size / 2" :cy="size / 2" :r="radius" fill="none" :stroke="color" stroke-width="10"
                stroke-linecap="round" :stroke-dasharray="circumference" :stroke-dashoffset="offset"
                class="transition-all duration-700 ease-out"
            />
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            <span class="text-2xl font-bold tabular-nums text-foreground">{{ Math.round(pct) }}%</span>
            <span v-if="label" class="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">{{ label }}</span>
        </div>
    </div>
</template>
