<script setup lang="ts">
import { computed, ref } from 'vue'
import { HEADLINE_METRICS, toneFor } from '@/lib/comparative-metrics'
import { money, percent, num } from '@/lib/format'

type Row = { label: string; prev: number; curr: number; diff: number; var_pct: number; fmt: 'currency' | 'percent' | 'integer' }

const props = defineProps<{
    rows: Row[]
    labelA: string
    labelB: string
}>()

const showAll = ref(true)

const visibleRows = computed(() => showAll.value ? props.rows : props.rows.filter(r => HEADLINE_METRICS.includes(r.label)))

function fmtValue(v: number, fmt: Row['fmt']): string {
    return fmt === 'percent' ? percent(v) : fmt === 'integer' ? num(v) : money(v)
}
</script>

<template>
    <div class="rounded-2xl border bg-white shadow-sm dark:bg-card">
        <div class="flex items-center justify-between border-b p-4 dark:border-slate-800">
            <div>
                <h3 class="text-xs font-black uppercase tracking-wider text-slate-500 dark:text-slate-400">Comparativo de métricas</h3>
                <p class="mt-0.5 text-[11px] text-slate-400 dark:text-slate-500">Todas las métricas disponibles para este alcance — nunca se ocultan de forma permanente.</p>
            </div>
            <button type="button" @click="showAll = !showAll"
                    class="h-8 shrink-0 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-card dark:text-slate-300 dark:hover:bg-slate-800">
                {{ showAll ? 'Mostrar principales' : 'Mostrar todas' }}
            </button>
        </div>
        <div class="max-h-[520px] overflow-auto">
            <table class="w-full text-sm">
                <thead class="sticky top-0 z-10 bg-slate-900 text-white">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-xs font-bold uppercase tracking-wide">Métrica</th>
                        <th class="px-4 py-2.5 text-right text-xs font-bold uppercase tracking-wide">{{ labelA }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-bold uppercase tracking-wide">{{ labelB }}</th>
                        <th class="px-4 py-2.5 text-right text-xs font-bold uppercase tracking-wide">Diferencia</th>
                        <th class="px-4 py-2.5 text-right text-xs font-bold uppercase tracking-wide">Var %</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in visibleRows" :key="row.label" :class="i % 2 === 1 ? 'bg-slate-50/70 dark:bg-slate-800/40' : ''" class="border-b border-slate-100 dark:border-slate-800">
                        <td class="px-4 py-2.5 font-semibold text-slate-800 dark:text-slate-100">{{ row.label }}</td>
                        <td class="px-4 py-2.5 text-right text-slate-700 dark:text-slate-200">{{ fmtValue(row.curr, row.fmt) }}</td>
                        <td class="px-4 py-2.5 text-right text-slate-500 dark:text-slate-400">{{ fmtValue(row.prev, row.fmt) }}</td>
                        <td class="px-4 py-2.5 text-right font-semibold text-slate-700 dark:text-slate-200">
                            {{ row.diff >= 0 ? '+' : '−' }}{{ fmtValue(Math.abs(row.diff), row.fmt) }}
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <span class="font-bold" :class="{
                                'text-emerald-700 dark:text-emerald-400': toneFor(row.label, row.var_pct) === 'good',
                                'text-rose-700 dark:text-rose-400': toneFor(row.label, row.var_pct) === 'bad',
                                'text-slate-500 dark:text-slate-400': toneFor(row.label, row.var_pct) === 'neutral',
                            }">
                                {{ row.var_pct >= 0 ? '+' : '' }}{{ row.var_pct.toFixed(2) }}%
                            </span>
                        </td>
                    </tr>
                    <tr v-if="visibleRows.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-slate-400 dark:text-slate-500">Sin métricas para este alcance.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
