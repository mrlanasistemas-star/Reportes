<script setup lang="ts">
// "SEGUIMIENTO POR GESTOR" (14-oct-2026) — una tarjeta por cada OKR
// individual ligado al OKR de sucursal. Cada gestor con SU línea base, SU
// meta y SU colocación real (derivada del mismo archivo de la sucursal).
import { Link } from '@inertiajs/vue3'
import { ArrowRight, FileText, Info, Users } from 'lucide-vue-next'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import { avatarTone, initialsOf } from '@/components/okr/wizard/okrWizard'
import { formatByUnit } from '@/lib/okrFormat'

defineProps<{ tracking: any[]; contributions: any[] }>()

function incrementText(value: number | null, unit: string): string {
    if (value === null || value === undefined) {
        return '—'
    }

    const sign = value > 0 ? '+' : ''

    return unit === 'percentage' ? `${sign}${Number(value).toFixed(2)} pp` : `${sign}${formatByUnit(value, unit)}`
}

function ring(value: number | null): string {
    const v = Math.max(0, Math.min(100, Number(value ?? 0)))
    const color = v >= 100 ? '#10b981' : v >= 60 ? '#6366f1' : '#f59e0b'

    return `conic-gradient(${color} ${v * 3.6}deg, rgba(148,163,184,0.18) 0deg)`
}

function bars(kr: any): { week: number; value: number | null; height: number }[] {
    const series: (number | null)[] = kr.weekly_placement?.length
        ? kr.weekly_placement
        : (kr.history ?? []).map((h: any) => h.actual_value)
    const max = Math.max(1, ...series.map((v) => Math.abs(Number(v ?? 0))))

    return series.map((value, i) => ({ week: i + 1, value, height: value === null ? 6 : Math.max(8, (Math.abs(Number(value)) / max) * 100) }))
}
</script>

<template>
    <section class="space-y-3">
        <div class="flex items-center gap-2">
            <span class="flex size-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"><Users class="size-4" /></span>
            <p class="text-xs font-black uppercase tracking-[0.18em] text-foreground">Seguimiento por gestor</p>
            <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-bold tabular-nums">{{ tracking.length }}</span>
        </div>

        <div v-for="c in contributions" :key="c.kpi.id" class="flex items-start gap-2 rounded-xl border px-3 py-2 text-[11px]" :class="c.distribution_mismatch ? 'border-amber-500/30 bg-amber-500/5 text-amber-800 dark:text-amber-200' : 'border-emerald-500/30 bg-emerald-500/5 text-emerald-800 dark:text-emerald-200'">
            <Info class="mt-0.5 size-3.5 shrink-0" />
            <span>
                <strong>{{ c.kpi.name }}:</strong> meta de sucursal {{ formatByUnit(c.branch_target, c.kpi.unit) }} · Σ metas individuales {{ formatByUnit(c.children_target_sum, c.kpi.unit) }} · Σ real individual {{ formatByUnit(c.children_current_sum, c.kpi.unit) }}.
                <template v-if="c.distribution_mismatch"> No tienen que coincidir: los gestores sin meta individual también aportan a la sucursal.</template>
            </span>
        </div>

        <div class="grid grid-cols-1 gap-3 xl:grid-cols-2">
            <article
                v-for="g in tracking" :key="g.id"
                class="group relative overflow-hidden rounded-2xl border border-border bg-card p-4 transition duration-300 hover:-translate-y-1 hover:border-primary/40 hover:shadow-xl"
            >
                <div class="pointer-events-none absolute inset-x-0 top-0 h-0.5 bg-gradient-to-r opacity-0 transition-opacity duration-300 group-hover:opacity-100" :class="avatarTone(g.id)" />
                <header class="flex items-center gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-xs font-bold text-white shadow-md transition-transform duration-300 group-hover:scale-105" :class="avatarTone(g.id)">{{ initialsOf(g.employee) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-bold text-foreground">{{ g.employee }}</p>
                        <div class="mt-0.5 flex flex-wrap items-center gap-1">
                            <OkrStatusBadge kind="lifecycle" :value="g.lifecycle_status" />
                            <OkrStatusBadge v-if="g.health_status" kind="health" :value="g.health_status" />
                            <span v-if="!g.employee_active" class="rounded-full border border-border bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">Baja — histórico conservado</span>
                        </div>
                    </div>
                    <div class="relative flex size-14 shrink-0 items-center justify-center rounded-full transition-transform duration-500 group-hover:rotate-6" :style="{ background: ring(g.compliance) }" :title="`Cumplimiento ponderado ${g.compliance ?? 0}%`">
                        <span class="flex size-11 items-center justify-center rounded-full bg-card text-xs font-black tabular-nums text-foreground">{{ g.compliance === null ? '—' : `${Math.round(g.compliance)}%` }}</span>
                    </div>
                </header>

                <div v-if="!g.key_results.length" class="mt-3 rounded-xl border border-dashed border-amber-500/40 bg-amber-500/5 p-3 text-xs text-amber-700 dark:text-amber-300">
                    Este OKR individual no tiene Key Results — complétalo desde su detalle antes de activarlo.
                </div>

                <div v-for="kr in g.key_results" :key="kr.id" class="mt-3 rounded-xl bg-muted/30 p-3 transition-colors group-hover:bg-muted/40">
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate text-xs font-semibold text-foreground">{{ kr.kpi.name }} <span class="font-normal text-muted-foreground">· {{ kr.description }}</span></p>
                        <OkrStatusBadge v-if="kr.health_status" kind="health" :value="kr.health_status" />
                    </div>
                    <div class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1.5 text-[11px] sm:grid-cols-4">
                        <div><p class="text-muted-foreground">Línea base</p><p class="font-semibold tabular-nums text-foreground">{{ formatByUnit(kr.baseline_value, kr.kpi.unit) }}<span v-if="kr.baseline_source === 'manual_authorized'" class="ml-1 rounded bg-amber-500/10 px-1 text-[9px] font-bold text-amber-700 dark:text-amber-300">MANUAL</span></p></div>
                        <div><p class="text-muted-foreground">Meta individual</p><p class="font-bold tabular-nums text-primary">{{ formatByUnit(kr.target_value, kr.kpi.unit) }}</p></div>
                        <div><p class="text-muted-foreground">Real acumulado</p><p class="font-bold tabular-nums text-foreground">{{ formatByUnit(kr.current_value, kr.kpi.unit) }}</p></div>
                        <div><p class="text-muted-foreground">Incremento</p><p class="font-semibold tabular-nums" :class="(kr.increment_achieved ?? 0) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">{{ incrementText(kr.increment_achieved, kr.kpi.unit) }} <span class="font-normal text-muted-foreground">/ {{ incrementText(kr.increment_target, kr.kpi.unit) }}</span></p></div>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <div class="relative h-2 flex-1 overflow-hidden rounded-full bg-muted">
                            <div class="absolute inset-y-0 left-0 rounded-full bg-gradient-to-r from-indigo-500 to-emerald-500 transition-all duration-700" :style="{ width: `${Math.max(0, Math.min(100, kr.compliance ?? 0))}%` }" />
                            <div v-if="kr.expected_compliance !== null" class="absolute inset-y-0 w-0.5 bg-foreground/60" :style="{ left: `${Math.max(0, Math.min(100, kr.expected_compliance))}%` }" />
                        </div>
                        <span class="shrink-0 text-[11px] font-bold tabular-nums">{{ kr.compliance === null ? '—' : `${kr.compliance}%` }}</span>
                    </div>
                    <div v-if="bars(kr).length" class="mt-2.5 flex h-10 items-end gap-1">
                        <div v-for="b in bars(kr)" :key="b.week" class="group/bar relative flex-1">
                            <div class="w-full rounded-t transition-all duration-300 group-hover/bar:opacity-80" :class="b.value === null ? 'bg-amber-400/40' : 'bg-gradient-to-t from-indigo-500 to-violet-400'" :style="{ height: `${b.height * 0.4}px` }" />
                            <span class="pointer-events-none absolute -top-7 left-1/2 z-10 hidden -translate-x-1/2 whitespace-nowrap rounded-md bg-slate-900 px-1.5 py-0.5 text-[10px] font-semibold text-white shadow group-hover/bar:block">
                                S{{ b.week }}: {{ b.value === null ? 'sin dato' : formatByUnit(b.value, kr.kpi.unit) }}
                            </span>
                        </div>
                    </div>
                </div>

                <footer class="mt-3 flex items-center justify-between text-[11px]">
                    <span class="flex items-center gap-1 text-muted-foreground"><FileText class="size-3.5" /> {{ g.evidences_count }} evidencia(s)</span>
                    <Link :href="g.url" class="flex items-center gap-1 font-bold text-primary">Ver detalle <ArrowRight class="size-3.5 transition-transform group-hover:translate-x-1" /></Link>
                </footer>
            </article>
        </div>
    </section>
</template>
