<script setup lang="ts">
// Paso 4 — resumen previo al guardado: meta de sucursal + metas por gestor,
// todo con formato de su unidad (Base $638,576.00 → Meta $838,576.00).
import { ArrowRight, Building2, CalendarDays, Info, ListChecks, Sparkles, UserCheck, Users2 } from 'lucide-vue-next'
import { computed } from 'vue'
import { formatByUnit, formatFriendlyDate } from '@/lib/okrFormat'
import { avatarTone, initialsOf, isAutomatic, kpiIconFor, round2 } from './okrWizard'
import type { GestorPlan, KrRow } from './okrWizard'

const props = defineProps<{
    kpis: any[]
    title: string
    branchName: string | null
    responsibleName: string
    durationWeeks: number
    startDate: string
    endDate: string | null
    branchRows: KrRow[]
    gestores: GestorPlan[]
}>()

function kpiOf(id: number | null) {
    return props.kpis.find((k) => k.id === id) ?? null
}

function baselineText(kr: KrRow): string {
    const kpi = kpiOf(kr.kpi_id)

    if (kr.baseline_value === '') {
        return isAutomatic(kpi) ? 'se congela al activar' : '—'
    }

    return formatByUnit(kr.baseline_value, kpi?.unit)
}

function incrementText(kr: KrRow): string | null {
    if (kr.baseline_value === '' || kr.target_value === '') {
        return null
    }

    const kpi = kpiOf(kr.kpi_id)
    const n = round2(Number(kr.target_value) - Number(kr.baseline_value))
    const sign = n > 0 ? '+' : ''

    return kpi?.unit === 'percentage' ? `${sign}${n.toFixed(2)} pp` : `${sign}${formatByUnit(n, kpi?.unit)}`
}

// Σ metas individuales vs meta de sucursal por KPI distribuible — solo
// informativo: puede haber gestores sin meta individual que también aportan.
const distribution = computed(() => props.branchRows
    .filter((kr) => kpiOf(kr.kpi_id) && kpiOf(kr.kpi_id).type !== 'percentage' && kr.target_value !== '')
    .map((kr) => {
        const sum = round2(props.gestores.reduce((acc, g) => acc + g.key_results
            .filter((gkr) => gkr.kpi_id === kr.kpi_id && gkr.target_value !== '')
            .reduce((s, gkr) => s + Number(gkr.target_value), 0), 0))

        return { kpi: kpiOf(kr.kpi_id), branchTarget: Number(kr.target_value), sum }
    })
    .filter((d) => d.sum > 0))
</script>

<template>
    <div class="space-y-4">
        <div class="relative overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-indigo-500/10 via-background to-emerald-500/10 p-4">
            <div class="pointer-events-none absolute -right-10 -top-10 size-40 rounded-full bg-indigo-500/10 blur-2xl" />
            <div class="relative flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30"><Sparkles class="size-5" /></span>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-muted-foreground">Objetivo general — {{ branchName }}</p>
                    <p class="text-sm font-semibold text-foreground">{{ title }}</p>
                </div>
            </div>
            <div class="relative mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div v-for="item in [
                    { icon: Building2, label: 'Sucursal', value: branchName, tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-300' },
                    { icon: UserCheck, label: 'Responsable', value: responsibleName, tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-300' },
                    { icon: CalendarDays, label: 'Plazo', value: `${durationWeeks} semanas`, tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-300' },
                    { icon: Users2, label: 'OKR individuales', value: String(gestores.length), tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300' },
                ]" :key="item.label" class="flex items-center gap-2 rounded-xl border border-border bg-background/70 p-2.5 backdrop-blur transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md">
                    <span class="flex size-8 shrink-0 items-center justify-center rounded-full" :class="item.tone"><component :is="item.icon" class="size-4" /></span>
                    <div class="min-w-0"><p class="text-[10px] text-muted-foreground">{{ item.label }}</p><p class="truncate text-xs font-semibold text-foreground">{{ item.value ?? '—' }}</p></div>
                </div>
            </div>
            <p class="relative mt-2 flex items-center gap-1.5 text-[11px] text-muted-foreground"><CalendarDays class="size-3.5" /> {{ formatFriendlyDate(startDate) }} → {{ endDate ? formatFriendlyDate(endDate) : '—' }}</p>
        </div>

        <!-- Meta de sucursal -->
        <div class="app-card space-y-2.5 p-4">
            <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-muted-foreground"><ListChecks class="size-3.5 text-primary" /> Meta de sucursal</p>
            <div v-for="kr in branchRows" :key="kr.client_key" class="group flex flex-wrap items-center gap-3 rounded-xl border border-border bg-muted/20 p-3 text-xs transition hover:border-primary/40 hover:bg-primary/5">
                <span class="flex size-8 shrink-0 items-center justify-center rounded-full transition-transform group-hover:scale-110" :class="kpiIconFor(kpiOf(kr.kpi_id)).class"><component :is="kpiIconFor(kpiOf(kr.kpi_id)).icon" class="size-4" /></span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold text-foreground">{{ kr.description }} <span class="font-normal text-muted-foreground">— {{ kpiOf(kr.kpi_id)?.name }}</span></p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-1.5 tabular-nums text-muted-foreground">
                        Base <strong class="text-foreground">{{ baselineText(kr) }}</strong>
                        <ArrowRight class="size-3" />
                        Meta <strong class="text-primary">{{ formatByUnit(kr.target_value, kpiOf(kr.kpi_id)?.unit) }}</strong>
                        <span v-if="incrementText(kr)" class="rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300">{{ incrementText(kr) }}</span>
                    </p>
                </div>
                <span class="text-sm font-bold tabular-nums text-foreground">{{ kr.weight }}%</span>
            </div>
        </div>

        <!-- Metas por gestor -->
        <div v-if="gestores.length" class="app-card space-y-3 p-4">
            <p class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-muted-foreground"><Users2 class="size-3.5 text-primary" /> Metas por gestor ({{ gestores.length }})</p>
            <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
                <div v-for="g in gestores" :key="g.client_key" class="rounded-xl border border-border bg-card p-3 transition duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg">
                    <div class="mb-2 flex items-center gap-2.5">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-[11px] font-bold text-white shadow" :class="avatarTone(g.employee_id)">{{ initialsOf(g.employee_name) }}</span>
                        <div class="min-w-0">
                            <p class="truncate text-xs font-bold text-foreground">{{ g.employee_name }}</p>
                            <p class="truncate text-[11px] text-muted-foreground">{{ g.title }}</p>
                        </div>
                    </div>
                    <div v-for="kr in g.key_results" :key="kr.client_key" class="mt-1.5 rounded-lg bg-muted/30 px-2.5 py-2 text-[11px]">
                        <p class="font-semibold text-foreground">{{ kpiOf(kr.kpi_id)?.name }} <span class="font-normal text-muted-foreground">· {{ kr.weight }}%</span></p>
                        <p class="flex flex-wrap items-center gap-1.5 tabular-nums text-muted-foreground">
                            Base <strong class="text-foreground">{{ baselineText(kr) }}</strong>
                            <span class="rounded bg-sky-500/10 px-1 text-[9px] font-bold text-sky-700 dark:text-sky-300">MANUAL DEL GESTOR</span>
                            <ArrowRight class="size-3" />
                            Meta <strong class="text-primary">{{ formatByUnit(kr.target_value, kpiOf(kr.kpi_id)?.unit) }}</strong>
                            <span v-if="incrementText(kr)" class="text-emerald-600 dark:text-emerald-400">({{ incrementText(kr) }})</span>
                        </p>
                    </div>
                </div>
            </div>

            <div v-for="d in distribution" :key="d.kpi.id" class="flex items-start gap-2 rounded-xl border px-3 py-2 text-[11px]" :class="Math.abs(d.branchTarget - d.sum) > 0.01 ? 'border-amber-500/30 bg-amber-500/5 text-amber-800 dark:text-amber-200' : 'border-emerald-500/30 bg-emerald-500/5 text-emerald-800 dark:text-emerald-200'">
                <Info class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    <strong>{{ d.kpi.name }}:</strong> Σ metas individuales {{ formatByUnit(d.sum, d.kpi.unit) }} vs meta de sucursal {{ formatByUnit(d.branchTarget, d.kpi.unit) }}.
                    <template v-if="Math.abs(d.branchTarget - d.sum) > 0.01">No necesitan coincidir: los gestores sin meta individual también aportan a la sucursal.</template>
                    <template v-else>Coinciden.</template>
                </span>
            </div>
        </div>

        <p class="flex items-center gap-2 rounded-xl bg-primary/5 px-3 py-2.5 text-xs font-medium text-primary">
            <Info class="size-4 shrink-0" /> Todo se guardará en BORRADOR. La línea base de la sucursal (Reportería) y las capturadas de cada gestor se congelan al activar (puedes activar la sucursal y sus individuales en un solo paso).
        </p>
    </div>
</template>
