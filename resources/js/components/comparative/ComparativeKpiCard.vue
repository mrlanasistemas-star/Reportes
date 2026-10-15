<script setup lang="ts">
import { computed } from 'vue'
import { money, percent, num } from '@/lib/format'
import ComparisonDeltaBadge from './ComparisonDeltaBadge.vue'

const props = defineProps<{
    label: string
    prev: number
    curr: number
    diff: number
    varPct: number
    fmt: 'currency' | 'percent' | 'integer'
    labelA: string
    labelB: string
}>()

const fmtValue = computed(() => (v: number) => props.fmt === 'percent' ? percent(v) : props.fmt === 'integer' ? num(v) : money(v))
</script>

<template>
    <div class="rounded-2xl border bg-card p-5 shadow-sm transition hover:shadow-md">
        <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ label }}</p>
        <div class="mt-3 flex flex-wrap items-end justify-between gap-x-3 gap-y-1">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ labelA }}</p>
                <p class="text-xl font-semibold text-foreground tabular-nums xl:text-2xl">{{ fmtValue(curr) }}</p>
            </div>
            <div class="ml-auto text-right">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ labelB }}</p>
                <p class="text-sm font-bold text-muted-foreground tabular-nums">{{ fmtValue(prev) }}</p>
            </div>
        </div>
        <div class="mt-3">
            <ComparisonDeltaBadge :label="label" :diff="diff" :var-pct="varPct" :fmt="fmt" />
        </div>
    </div>
</template>
