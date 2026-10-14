<script setup lang="ts">
// "OBJETIVO GENERAL — {SUCURSAL}" (14-oct-2026): por cada KR muestra línea
// base congelada → meta, incremento esperado vs alcanzado, real acumulado,
// cumplimiento real vs esperado, proyección y semáforo. Solo presenta lo que
// OkrSnapshotService ya evaluó; nunca recalcula.
import { ArrowRight, Lock, Target } from 'lucide-vue-next'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import { formatByUnit } from '@/lib/okrFormat'

defineProps<{ heading: string; keyResults: any[] }>()

function increment(kr: any, value: number | null): string {
    if (value === null || value === undefined || kr.baseline_value === null || kr.baseline_value === undefined) {
        return '—'
    }

    const n = Number(value) - Number(kr.baseline_value)
    const sign = n > 0 ? '+' : ''

    return kr.kpi.unit === 'percentage' ? `${sign}${n.toFixed(2)} pp` : `${sign}${formatByUnit(n, kr.kpi.unit)}`
}

function pct(v: number | null | undefined): number {
    return v === null || v === undefined ? 0 : Math.max(0, Math.min(100, Number(v)))
}
</script>

<template>
    <section class="relative overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-500 via-violet-500 to-emerald-500" />
        <div class="flex items-center gap-2 px-4 pb-2 pt-4">
            <span class="flex size-8 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-300"><Target class="size-4" /></span>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-foreground">{{ heading }}</p>
        </div>
        <div class="grid grid-cols-1 gap-3 p-4 pt-1 lg:grid-cols-2">
            <article v-for="kr in keyResults" :key="kr.id" class="group rounded-xl border border-border bg-gradient-to-br from-muted/30 to-background p-4 transition duration-300 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-foreground">{{ kr.kpi.name }}</p>
                        <p class="truncate text-[11px] text-muted-foreground">{{ kr.description }}</p>
                    </div>
                    <OkrStatusBadge v-if="kr.health_status" kind="health" :value="kr.health_status" />
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs tabular-nums">
                    <span class="flex items-center gap-1 rounded-lg bg-muted/60 px-2 py-1"><Lock v-if="kr.baseline_locked_at" class="size-3 text-muted-foreground" /> Base <strong class="text-foreground">{{ formatByUnit(kr.baseline_value, kr.kpi.unit) }}</strong></span>
                    <ArrowRight class="size-3.5 text-muted-foreground transition group-hover:translate-x-0.5" />
                    <span class="rounded-lg bg-primary/10 px-2 py-1 text-primary">Meta <strong>{{ formatByUnit(kr.target_value, kr.kpi.unit) }}</strong></span>
                    <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300">Incremento esperado {{ increment(kr, kr.target_value) }}</span>
                </div>

                <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-lg bg-muted/40 p-2">
                        <p class="text-[10px] text-muted-foreground">Real acumulado</p>
                        <p class="text-sm font-bold tabular-nums text-foreground">{{ formatByUnit(kr.current_value, kr.kpi.unit) }}</p>
                    </div>
                    <div class="rounded-lg bg-muted/40 p-2">
                        <p class="text-[10px] text-muted-foreground">Incremento alcanzado</p>
                        <p class="text-sm font-bold tabular-nums" :class="(kr.current_value ?? 0) >= (kr.baseline_value ?? 0) ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">{{ increment(kr, kr.current_value) }}</p>
                    </div>
                    <div class="rounded-lg bg-muted/40 p-2">
                        <p class="text-[10px] text-muted-foreground">Proyección al cierre</p>
                        <p class="text-sm font-bold tabular-nums text-foreground">{{ formatByUnit(kr.projected_value, kr.kpi.unit) }}</p>
                    </div>
                </div>

                <div class="mt-3 space-y-1.5">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="font-semibold text-foreground">Cumplimiento real {{ kr.actual_progress_percentage === null ? '—' : `${kr.actual_progress_percentage}%` }}</span>
                        <span class="text-muted-foreground">Esperado {{ kr.expected_progress_percentage === null ? '—' : `${kr.expected_progress_percentage}%` }}</span>
                    </div>
                    <div class="relative h-2.5 overflow-hidden rounded-full bg-muted">
                        <div class="absolute inset-y-0 left-0 rounded-full bg-gradient-to-r from-indigo-500 to-emerald-500 transition-all duration-700 ease-out" :style="{ width: `${pct(kr.actual_progress_percentage)}%` }" />
                        <div class="absolute inset-y-0 w-0.5 bg-foreground/60" :style="{ left: `${pct(kr.expected_progress_percentage)}%` }" title="Avance esperado a la fecha" />
                    </div>
                </div>
            </article>
        </div>
    </section>
</template>
