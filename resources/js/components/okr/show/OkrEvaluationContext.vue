<script setup lang="ts">
// Ficha de evaluación (4.5 / 8.10 §1, 19-oct-2026): deja EVIDENTE qué se
// evalúa — tipo de OKR (sucursal o persona), colaborador evaluado, número de
// empleado, puesto, sucursal, jefe inmediato, base, meta, incremento, semanas
// transcurridas — y QUÉ FALTA para poder evaluarlo. La persona evaluada es la
// del OKR (gestor o titular), nunca el usuario que lo creó.
import { BadgeCheck, Building2, CalendarClock, CircleAlert, CircleCheck, IdCard, Target, UserRound } from 'lucide-vue-next'
import { computed } from 'vue'
import { formatByUnit, formatFriendlyDate } from '@/lib/okrFormat'

const props = defineProps<{ objective: any; keyResults: any[]; canAssign: boolean; canUpdate: boolean }>()
const emit = defineEmits<{ activate: []; configure: []; letter: []; upload: []; documents: [] }>()

const isBranch = computed(() => props.objective.scope_type === 'branch')
const subject = computed(() => props.objective.subject ?? null)
const kr = computed(() => props.keyResults[0] ?? null)
const unit = computed(() => kr.value?.kpi?.unit)
const increment = computed(() => (kr.value && kr.value.baseline_value !== null ? Number(kr.value.target_value) - Number(kr.value.baseline_value) : null))
const elapsed = computed(() => Math.max(0, Math.min(props.objective.duration_weeks, props.objective.current_week ?? 0)))
const started = computed(() => new Date(`${props.objective.start_date}T00:00:00`) <= new Date())

type Pending = { text: string; action?: 'activate' | 'configure' | 'letter' | 'upload' | 'documents'; label?: string }

function run(action: NonNullable<Pending['action']>) {
    switch (action) {
        case 'activate': emit('activate')
            break
        case 'configure': emit('configure')
            break
        case 'letter': emit('letter')
            break
        case 'upload': emit('upload')
            break
        default: emit('documents')
    }
}

// Qué falta para evaluar — derivado de los datos reales del OKR (sin inventar).
const pending = computed<Pending[]>(() => {
    const o = props.objective
    const out: Pending[] = []

    if (!props.keyResults.length) {
        out.push({ text: 'Configurar los Key Results (KPI, línea base, incremento y meta).', action: 'configure', label: 'Configurar KR' })
    }

    if (isBranch.value && !subject.value) {
        out.push({ text: 'Asignar el titular del OKR de sucursal (quien acepta la responsabilidad general).', action: 'letter', label: 'Asignar titular' })
    }

    if (o.lifecycle_status === 'draft') {
        out.push({ text: 'Activar el OKR: al activarlo se congela la línea base.', action: 'activate', label: 'Activar OKR' })
    }

    if (!started.value) {
        out.push({ text: `El OKR inicia el ${formatFriendlyDate(o.start_date)}; todavía no hay semanas que evaluar.` })
    }

    if (o.lifecycle_status === 'active' && !o.commitment_letter) {
        out.push({ text: 'Emitir la Carta Compromiso (los Warnings se emiten con base en ella).', action: 'letter', label: 'Revisar carta' })
    } else if (o.commitment_letter && !o.commitment_letter.is_signed) {
        out.push({ text: `Subir la copia firmada de la carta ${o.commitment_letter.folio}.`, action: 'documents', label: 'Ir a documentos' })
    }

    const weeks = (o.placement_weeks ?? []) as any[]
    const missingFiles = weeks.filter((w) => !w.uploaded && w.week_number < (o.current_week ?? 0)).map((w) => w.week_number)

    if (missingFiles.length) {
        out.push({ text: `Falta cargar el reporte semanal de colocación de la(s) semana(s) ${missingFiles.join(', ')}.`, action: isBranch.value ? 'upload' : undefined, label: isBranch.value ? 'Cargar colocación' : undefined })
    }

    const partialAbsent = isBranch.value ? [] : weeks.filter((w) => w.uploaded && w.counted_amount === null).map((w) => w.week_number)

    if (partialAbsent.length) {
        out.push({ text: `Semana(s) ${partialAbsent.join(', ')}: no aparece en el reporte parcial de la sucursal; queda pendiente hasta cargar el reporte completo.` })
    }

    return out
})
</script>

<template>
    <section class="rounded-2xl border border-border bg-card shadow-sm" data-tour="okr-evaluation-context">
        <div class="grid grid-cols-1 gap-0 lg:grid-cols-[1.4fr_1fr]">
            <!-- Quién y qué se evalúa -->
            <div class="space-y-3 p-4 sm:p-5">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold" :class="isBranch ? 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'">
                        <component :is="isBranch ? Building2 : UserRound" class="size-3.5" /> {{ isBranch ? 'OKR de sucursal' : 'OKR individual' }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-[11px] font-semibold text-muted-foreground"><CalendarClock class="size-3.5" /> Semana {{ elapsed }} de {{ objective.duration_weeks }} · {{ formatFriendlyDate(objective.start_date) }} – {{ formatFriendlyDate(objective.end_date) }}</span>
                </div>

                <div class="flex items-start gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary"><Target class="size-5" /></span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Objetivo</p>
                        <p class="text-[15px] font-bold leading-snug text-foreground">{{ objective.title }}</p>
                    </div>
                </div>

                <dl class="grid grid-cols-1 gap-x-6 gap-y-2.5 text-xs sm:grid-cols-2">
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">{{ isBranch ? 'Titular evaluado' : 'Colaborador evaluado' }}</dt>
                        <dd v-if="subject" class="font-semibold text-foreground">{{ subject.full_name }}<span v-if="subject.is_active === false" class="ml-1 rounded bg-muted px-1 text-[10px] font-bold">Baja</span></dd>
                        <dd v-else class="font-semibold text-amber-700 dark:text-amber-300">{{ isBranch ? 'Sin titular asignado' : 'Sin colaborador' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Número de empleado</dt>
                        <dd class="flex items-center gap-1 font-mono font-semibold text-foreground"><IdCard class="size-3.5 text-muted-foreground" /> {{ subject?.employee_code ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Puesto</dt>
                        <dd class="font-semibold text-foreground">{{ subject?.position ?? 'No registrado en el directorio' }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Sucursal</dt>
                        <dd class="font-semibold text-foreground">{{ objective.branch?.name ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Jefe inmediato</dt>
                        <dd v-if="subject?.immediate_boss" class="flex flex-wrap items-center gap-1.5 font-semibold text-foreground">
                            {{ subject.immediate_boss }}
                            <span v-if="subject.immediate_boss_confirmed" class="inline-flex items-center gap-0.5 rounded-full bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 dark:text-emerald-300"><BadgeCheck class="size-3" /> Confirmado en la carta</span>
                            <span v-else class="rounded-full bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-bold text-amber-700 dark:text-amber-300">Sugerido por el directorio · por confirmar</span>
                        </dd>
                        <dd v-else class="font-semibold text-muted-foreground">Por confirmar en la revisión de la carta</dd>
                    </div>
                </dl>

                <div v-if="kr" class="grid grid-cols-3 gap-2 rounded-xl bg-muted/40 p-2.5 text-xs">
                    <div><p class="text-[10px] font-bold uppercase text-muted-foreground">Línea base</p><p class="font-bold tabular-nums text-foreground">{{ formatByUnit(kr.baseline_value, unit) }}</p></div>
                    <div><p class="text-[10px] font-bold uppercase text-muted-foreground">Incremento</p><p class="font-bold tabular-nums text-foreground">{{ increment === null ? '—' : formatByUnit(increment, unit) }}</p></div>
                    <div><p class="text-[10px] font-bold uppercase text-muted-foreground">Meta</p><p class="font-bold tabular-nums text-primary">{{ formatByUnit(kr.target_value, unit) }}</p></div>
                </div>
            </div>

            <!-- Qué falta para evaluar -->
            <div class="border-t border-border bg-muted/20 p-4 sm:p-5 lg:border-l lg:border-t-0">
                <p class="mb-2 text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Qué falta para evaluar</p>
                <ul v-if="pending.length" class="space-y-2">
                    <li v-for="p in pending" :key="p.text" class="flex items-start gap-2 rounded-xl border border-amber-500/25 bg-amber-500/5 px-3 py-2 text-xs text-amber-900 dark:text-amber-100">
                        <CircleAlert class="mt-0.5 size-3.5 shrink-0 text-amber-600" />
                        <span class="flex-1">{{ p.text }}</span>
                        <button
                            v-if="p.action && (p.action !== 'activate' || canAssign) && (p.action !== 'configure' || canUpdate)"
                            type="button" class="shrink-0 font-bold text-primary hover:underline" @click="run(p.action)"
                        >{{ p.label }} →</button>
                    </li>
                </ul>
                <p v-else class="flex items-center gap-2 rounded-xl border border-emerald-500/25 bg-emerald-500/5 px-3 py-2.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                    <CircleCheck class="size-4" /> Todo listo: el OKR tiene datos suficientes para evaluarse semana a semana.
                </p>
            </div>
        </div>
    </section>
</template>
