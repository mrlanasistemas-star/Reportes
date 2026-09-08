<script setup lang="ts">
// Mini-tendencia sin ejes/leyenda (sección 22 del pedido) — SVG inline, sin
// dependencia nueva (evita cargar ApexCharts/ChartCard solo para esto).
import { computed } from 'vue'

const props = withDefaults(defineProps<{ values: number[]; width?: number; height?: number }>(), {
    width: 72,
    height: 24,
})

const points = computed(() => {
    const vals = props.values
    if (!vals || vals.length < 2) return null

    const min = Math.min(...vals)
    const max = Math.max(...vals)
    const range = max - min || 1
    const stepX = props.width / (vals.length - 1)

    return vals.map((v, i) => `${(i * stepX).toFixed(1)},${(props.height - ((v - min) / range) * props.height).toFixed(1)}`).join(' ')
})

const trendUp = computed(() => props.values && props.values.length >= 2 && props.values.at(-1)! >= props.values[0])
</script>

<template>
    <svg v-if="points" :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`" class="overflow-visible">
        <polyline
            :points="points"
            fill="none"
            :stroke="trendUp ? '#10b981' : '#f59e0b'"
            stroke-width="1.75"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
    <span v-else class="text-xs text-muted-foreground">—</span>
</template>
