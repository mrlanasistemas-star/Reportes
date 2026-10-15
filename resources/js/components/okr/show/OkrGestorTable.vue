<script setup lang="ts">
// SEGUIMIENTO POR GESTOR (15-oct-2026) — sección protagonista del OKR de
// sucursal: una fila por cada OKR individual ligado, con su puesto, estado,
// base, meta, incrementos, cumplimiento, semáforo y acciones por persona.
// Un borrador muestra exactamente qué le falta (nunca un mensaje confuso).
import { Link, router } from '@inertiajs/vue3'
import { AlertTriangle, ChevronRight, Eye, FileSignature, FolderOpen, History, MoreHorizontal, Settings2, ShieldAlert, Users } from 'lucide-vue-next'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import { avatarTone, initialsOf } from '@/components/okr/wizard/okrWizard'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { formatByUnit } from '@/lib/okrFormat'

defineProps<{ tracking: any[]; contributions: any[]; currentWeek: number; durationWeeks: number; canUpdate: boolean }>()
const emit = defineEmits<{ configure: [row: any] }>()

function signed(value: number | null, unit: string): string {
    if (value === null || value === undefined) {
        return '—'
    }

    return unit === 'percentage' ? `${value > 0 ? '+' : ''}${Number(value).toFixed(2)} pp` : `${value > 0 ? '+' : ''}${formatByUnit(value, unit)}`
}

function achieved(kr: any, currentWeek: number, durationWeeks: number): number | null {
    if (kr.current_value === null || kr.baseline_value === null) {
        return null
    }

    const weeklyFlow = kr.kpi.type === 'cumulative' && (kr.weekly_placement ?? []).length > 0

    const lastWeek = [...(kr.history ?? [])].reverse().find((h: any) => h.actual_value !== null)?.week_number ?? currentWeek

    return weeklyFlow
        ? Number(kr.current_value) - Number(kr.baseline_value) * Math.min(1, lastWeek / Math.max(1, durationWeeks))
        : Number(kr.current_value) - Number(kr.baseline_value)
}

function goTo(row: any, panel?: string) {
    router.get(row.url + (panel ? `?panel=${panel}` : ''))
}
</script>

<template>
    <section data-tour="okr-gestores" class="rounded-2xl border border-border bg-card shadow-sm">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3">
            <div class="flex items-center gap-2">
                <span class="flex size-9 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-300"><Users class="size-4" /></span>
                <div>
                    <p class="text-sm font-bold text-foreground">Seguimiento por gestor</p>
                    <p class="text-[11px] text-muted-foreground">Cada gestor con su propia base, meta y colocación (del mismo archivo de la sucursal).</p>
                </div>
            </div>
            <span class="rounded-full bg-muted px-2.5 py-1 text-[11px] font-bold tabular-nums">{{ tracking.length }} OKR individual(es)</span>
        </header>

        <div v-for="c in contributions" :key="c.kpi.id" class="mx-4 mt-3 rounded-xl border px-3 py-2 text-[11px]" :class="c.distribution_mismatch ? 'border-amber-500/30 bg-amber-500/5 text-amber-800 dark:text-amber-200' : 'border-emerald-500/30 bg-emerald-500/5 text-emerald-800 dark:text-emerald-200'">
            <strong>{{ c.kpi.name }}:</strong> meta de sucursal {{ formatByUnit(c.branch_target, c.kpi.unit) }} · Σ metas individuales {{ formatByUnit(c.children_target_sum, c.kpi.unit) }} · Σ real individual {{ formatByUnit(c.children_current_sum, c.kpi.unit) }}.
            <template v-if="c.distribution_mismatch"> No necesitan coincidir: los gestores sin meta individual también aportan a la sucursal.</template>
        </div>

        <div class="overflow-x-auto p-4">
            <table class="w-full min-w-[1080px] text-xs">
                <thead class="text-muted-foreground">
                    <tr class="border-b border-border">
                        <th class="px-2 py-2 text-left font-semibold">Gestor</th>
                        <th class="px-2 py-2 text-left font-semibold">Estado OKR</th>
                        <th class="px-2 py-2 text-right font-semibold">Línea base</th>
                        <th class="px-2 py-2 text-right font-semibold">Meta</th>
                        <th class="px-2 py-2 text-right font-semibold">Inc. esperado</th>
                        <th class="px-2 py-2 text-right font-semibold">Inc. alcanzado</th>
                        <th class="w-40 px-2 py-2 text-left font-semibold">Cumplimiento</th>
                        <th class="px-2 py-2 text-left font-semibold">Semáforo</th>
                        <th class="px-2 py-2 text-right font-semibold">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="g in tracking" :key="g.id">
                        <tr class="group cursor-pointer border-b border-border/60 transition-colors hover:bg-muted/40" @click="goTo(g)">
                            <td class="px-2 py-3">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-[10px] font-bold text-white shadow-sm transition-transform group-hover:scale-110" :class="avatarTone(g.id)">{{ initialsOf(g.employee) }}</span>
                                    <div class="min-w-0">
                                        <p class="truncate font-bold text-foreground">{{ g.employee }}</p>
                                        <p class="truncate text-[11px] text-muted-foreground">{{ g.position ?? 'Puesto no registrado' }}<span v-if="!g.employee_active" class="ml-1 rounded bg-muted px-1 font-semibold">Baja</span></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-2 py-3"><OkrStatusBadge kind="lifecycle" :value="g.lifecycle_status" /></td>
                            <template v-if="g.key_results.length">
                                <td class="px-2 py-3 text-right tabular-nums">{{ formatByUnit(g.key_results[0].baseline_value, g.key_results[0].kpi.unit) }}</td>
                                <td class="px-2 py-3 text-right font-bold tabular-nums text-primary">{{ formatByUnit(g.key_results[0].target_value, g.key_results[0].kpi.unit) }}<span v-if="g.key_results.length > 1" class="block text-[10px] font-normal text-muted-foreground">+{{ g.key_results.length - 1 }} KR</span></td>
                                <td class="px-2 py-3 text-right tabular-nums">{{ signed(g.key_results[0].increment_target, g.key_results[0].kpi.unit) }}</td>
                                <td class="px-2 py-3 text-right font-semibold tabular-nums" :class="(achieved(g.key_results[0], currentWeek, durationWeeks) ?? 0) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">{{ signed(achieved(g.key_results[0], currentWeek, durationWeeks), g.key_results[0].kpi.unit) }}</td>
                                <td class="px-2 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="relative h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                            <div class="absolute inset-y-0 left-0 rounded-full bg-gradient-to-r from-indigo-500 to-emerald-500 transition-all duration-700" :style="{ width: `${Math.max(0, Math.min(100, g.compliance ?? 0))}%` }" />
                                        </div>
                                        <span class="w-10 text-right font-bold tabular-nums">{{ g.lifecycle_status === 'draft' ? '—' : `${Math.round(g.compliance ?? 0)}%` }}</span>
                                    </div>
                                </td>
                                <td class="px-2 py-3"><OkrStatusBadge v-if="g.health_status" kind="health" :value="g.health_status" /><span v-else class="text-muted-foreground">Pendiente</span></td>
                            </template>
                            <td v-else colspan="6" class="px-2 py-3">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-2.5 py-1 font-semibold text-amber-700 dark:text-amber-300"><AlertTriangle class="size-3.5" /> Sin Key Results — configúralos para poder activarlo</span>
                            </td>
                            <td class="px-2 py-3 text-right" @click.stop>
                                <div class="flex items-center justify-end gap-1.5">
                                    <Button v-if="!g.key_results.length && canUpdate && g.lifecycle_status === 'draft'" size="sm" class="h-8 gap-1 rounded-lg text-xs" @click="emit('configure', g)"><Settings2 class="size-3.5" /> Configurar KR</Button>
                                    <span v-if="g.letter" class="hidden rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-bold text-emerald-700 xl:inline dark:text-emerald-300" :title="g.letter.folio">Carta {{ g.letter.is_signed ? 'firmada' : 'emitida' }}</span>
                                    <span v-if="g.warnings_count" class="rounded-full bg-rose-500/10 px-2 py-0.5 text-[10px] font-bold text-rose-700 dark:text-rose-300">{{ g.warnings_count }} warning(s)</span>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="icon" class="size-8 rounded-lg" :aria-label="`Acciones de ${g.employee}`"><MoreHorizontal class="size-4" /></Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" class="w-56">
                                            <DropdownMenuItem @click="goTo(g)"><Eye class="size-4" /> Ver detalle</DropdownMenuItem>
                                            <DropdownMenuItem v-if="canUpdate && g.lifecycle_status === 'draft'" @click="emit('configure', g)"><Settings2 class="size-4" /> {{ g.key_results.length ? 'Editar metas' : 'Configurar KR' }}</DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem @click="goTo(g, 'letter')"><FileSignature class="size-4" /> Revisar Carta Compromiso</DropdownMenuItem>
                                            <DropdownMenuItem @click="goTo(g, 'warning')"><ShieldAlert class="size-4" /> Warnings</DropdownMenuItem>
                                            <DropdownMenuItem @click="goTo(g, 'documents')"><FolderOpen class="size-4" /> Evidencias</DropdownMenuItem>
                                            <DropdownMenuItem @click="goTo(g, 'history')"><History class="size-4" /> Histórico semanal</DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                    <Link :href="g.url" class="hidden text-primary transition-transform group-hover:translate-x-0.5 sm:inline" aria-label="Ver detalle"><ChevronRight class="size-4" /></Link>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="g.issues?.length && g.key_results.length" class="border-b border-border/60 bg-amber-500/[0.04]">
                            <td colspan="9" class="px-4 py-1.5 text-[11px] text-amber-800 dark:text-amber-200"><AlertTriangle class="mr-1 inline size-3.5" /> Para activarlo falta: {{ g.issues.join(' · ') }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>
</template>
