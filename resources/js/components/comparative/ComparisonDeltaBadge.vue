<script setup lang="ts">
import { ArrowUp, ArrowDown, Minus } from 'lucide-vue-next'
import { computed } from 'vue'
import { toneFor } from '@/lib/comparative-metrics'
import { money, percent, num } from '@/lib/format'

const props = defineProps<{
    label: string
    diff: number
    varPct: number
    fmt: 'currency' | 'percent' | 'integer'
}>()

const tone = computed(() => toneFor(props.label, props.varPct))

const toneClasses = computed(() => ({
    good: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    bad: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    neutral: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
}[tone.value]))

const diffLabel = computed(() => {
    const abs = Math.abs(props.diff)
    const formatted = props.fmt === 'percent' ? percent(abs) : props.fmt === 'integer' ? num(abs) : money(abs)
    const sign = props.diff > 0 ? '+' : props.diff < 0 ? '−' : ''

    return `${sign}${formatted}`
})
</script>

<template>
    <span :class="toneClasses" class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold">
        <ArrowUp v-if="varPct > 0" class="size-3" />
        <ArrowDown v-else-if="varPct < 0" class="size-3" />
        <Minus v-else class="size-3" />
        {{ diffLabel }}
        <span class="opacity-70">({{ varPct >= 0 ? '+' : '' }}{{ varPct.toFixed(2) }}%)</span>
    </span>
</template>
