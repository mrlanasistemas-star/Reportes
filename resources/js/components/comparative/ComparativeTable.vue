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
    <div class="app-table-wrap">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4">
            <div class="min-w-0">
                <h3 class="app-section-title">Comparativo de métricas</h3>
                <p class="app-help mt-0.5">Todas las métricas disponibles para este alcance — nunca se ocultan de forma permanente.</p>
            </div>
            <button type="button" @click="showAll = !showAll"
                    class="h-9 shrink-0 rounded-lg border border-border bg-card px-3 text-xs font-semibold text-foreground transition hover:bg-muted">
                {{ showAll ? 'Mostrar principales' : 'Mostrar todas' }}
            </button>
        </div>
        <div class="max-h-[520px] overflow-auto">
            <table class="w-full text-sm">
                <thead class="sticky top-0 z-10 bg-muted text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide whitespace-nowrap">Métrica</th>
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wide whitespace-nowrap">{{ labelA }}</th>
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wide whitespace-nowrap">{{ labelB }}</th>
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wide whitespace-nowrap">Diferencia</th>
                        <th class="px-4 py-2.5 text-right text-[11px] font-semibold uppercase tracking-wide whitespace-nowrap">Var %</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in visibleRows" :key="row.label" :class="i % 2 === 1 ? 'bg-muted/40' : ''" class="border-b border-border transition-colors hover:bg-muted/60">
                        <td class="px-4 py-2.5 font-semibold text-foreground">{{ row.label }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums whitespace-nowrap text-foreground/80">{{ fmtValue(row.curr, row.fmt) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums whitespace-nowrap text-muted-foreground">{{ fmtValue(row.prev, row.fmt) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums whitespace-nowrap font-semibold text-foreground/80">
                            {{ row.diff >= 0 ? '+' : '−' }}{{ fmtValue(Math.abs(row.diff), row.fmt) }}
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums whitespace-nowrap">
                            <span class="font-bold" :class="{
                                'text-emerald-700 dark:text-emerald-400': toneFor(row.label, row.var_pct) === 'good',
                                'text-rose-700 dark:text-rose-400': toneFor(row.label, row.var_pct) === 'bad',
                                'text-muted-foreground ': toneFor(row.label, row.var_pct) === 'neutral',
                            }">
                                {{ row.var_pct >= 0 ? '+' : '' }}{{ row.var_pct.toFixed(2) }}%
                            </span>
                        </td>
                    </tr>
                    <tr v-if="visibleRows.length === 0">
                        <td colspan="5" class="px-4 py-8 text-center text-sm text-muted-foreground">Sin métricas para este alcance.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
