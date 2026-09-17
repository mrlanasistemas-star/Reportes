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
    <div class="rounded-2xl border bg-white p-5 shadow-sm transition hover:shadow-md">
        <p class="text-xs font-black uppercase tracking-wider text-slate-500">{{ label }}</p>
        <div class="mt-3 flex items-end justify-between gap-3">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ labelA }}</p>
                <p class="text-2xl font-black text-slate-950">{{ fmtValue(curr) }}</p>
            </div>
            <div class="text-right">
                <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ labelB }}</p>
                <p class="text-sm font-bold text-slate-500">{{ fmtValue(prev) }}</p>
            </div>
        </div>
        <div class="mt-3">
            <ComparisonDeltaBadge :label="label" :diff="diff" :var-pct="varPct" :fmt="fmt" />
        </div>
    </div>
</template>
