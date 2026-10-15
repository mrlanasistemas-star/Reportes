<script setup lang="ts">
// Colocación semanal (15-oct-2026): tabla compacta por semana con periodo,
// archivo, total, gestores y estado; "Ver desglose" expande cuánto colocó cada
// gestor (contado una sola vez) y qué quedó sin atribución o excluido.
import { ChevronDown, Eye, Loader2, RefreshCw, Upload } from 'lucide-vue-next'
import { computed, reactive, ref } from 'vue'
import { avatarTone, initialsOf } from '@/components/okr/wizard/okrWizard'
import { formatCurrency, formatFriendlyDate } from '@/lib/okrFormat'
import { okrRequest } from '@/lib/okrHttp'

const props = defineProps<{ objectiveId: number; weeks: any[]; currentWeek: number; canUpload: boolean; isChild: boolean }>()
const emit = defineEmits<{ upload: [week: any] }>()

// Solo lo relevante al inicio (cargadas, la actual y la siguiente); el resto se despliega.
const showAll = ref(false)
const visibleWeeks = computed(() => (showAll.value ? props.weeks : props.weeks.filter((w: any) => w.uploaded || w.week_number <= props.currentWeek + 1)))
const hiddenCount = computed(() => props.weeks.length - props.weeks.filter((w: any) => w.uploaded || w.week_number <= props.currentWeek + 1).length)

const expanded = reactive<Record<number, { loading: boolean; data: any | null; error: string | null } | undefined>>({})

async function toggle(week: number) {
    if (expanded[week]) {
        expanded[week] = undefined

        return
    }

    expanded[week] = { loading: true, data: null, error: null }
    const res = await okrRequest(`/okr/${props.objectiveId}/placement-weeks/${week}`)
    expanded[week] = { loading: false, data: res.ok ? res.data : null, error: res.ok ? null : res.message }
}

function status(w: any): { label: string; class: string } {
    if (w.uploaded) {
        // 4.6: un gestor ausente de un reporte parcial queda PENDIENTE (nunca $0).
        if (props.isChild && w.counted_amount === null) {
            return { label: 'Pendiente · no aparece en el reporte parcial', class: 'bg-amber-500/10 text-amber-700 dark:text-amber-300' }
        }

        return w.declared_complete === true || w.coverage_status === 'full'
            ? { label: 'Reporte completo', class: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' }
            : { label: 'Reporte parcial', class: 'bg-sky-500/10 text-sky-700 dark:text-sky-300' }
    }

    if (w.week_number < props.currentWeek) {
        return { label: 'Falta archivo', class: 'bg-amber-500/10 text-amber-700 dark:text-amber-300' }
    }

    if (w.week_number === props.currentWeek) {
        return { label: 'En curso', class: 'bg-primary/10 text-primary' }
    }

    return { label: 'Próxima', class: 'bg-muted text-muted-foreground' }
}
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[760px] text-xs">
            <thead class="bg-muted/40 text-muted-foreground">
                <tr>
                    <th class="px-3 py-2 text-left font-semibold">Semana</th>
                    <th class="px-3 py-2 text-left font-semibold">Periodo</th>
                    <th class="px-3 py-2 text-left font-semibold">Archivo</th>
                    <th class="px-3 py-2 text-right font-semibold">{{ isChild ? 'Colocación del gestor' : 'Total sucursal' }}</th>
                    <th v-if="!isChild" class="px-3 py-2 text-center font-semibold">Gestores</th>
                    <th v-if="!isChild" class="px-3 py-2 text-right font-semibold">No atribuible</th>
                    <th class="px-3 py-2 text-left font-semibold">Estado</th>
                    <th class="px-3 py-2 text-right font-semibold">Acción</th>
                </tr>
            </thead>
            <tbody>
                <template v-for="w in visibleWeeks" :key="w.week_number">
                    <tr class="border-t border-border transition-colors hover:bg-muted/30" :class="w.week_number === currentWeek ? 'bg-primary/[0.03]' : ''">
                        <td class="px-3 py-2.5 font-bold text-foreground">S{{ w.week_number }}</td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-muted-foreground">{{ formatFriendlyDate(w.week_start) }} – {{ formatFriendlyDate(w.week_end) }}</td>
                        <td class="max-w-[200px] px-3 py-2.5">
                            <span v-if="w.uploaded" class="block truncate text-foreground" :title="w.original_filename">{{ w.original_filename }}</span>
                            <span v-if="w.from_branch_file" class="text-[10px] text-muted-foreground">archivo de la sucursal</span>
                            <span v-if="!w.uploaded" class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2.5 text-right font-semibold tabular-nums text-foreground">
                            <template v-if="!w.uploaded">—</template>
                            <span v-else-if="isChild && w.counted_amount === null" class="text-[11px] font-semibold text-amber-700 dark:text-amber-300">Sin dato</span>
                            <template v-else>{{ formatCurrency(w.counted_amount ?? w.total_amount) }}</template>
                        </td>
                        <td v-if="!isChild" class="px-3 py-2.5 text-center tabular-nums">{{ w.uploaded ? w.gestores_count : '—' }}</td>
                        <td v-if="!isChild" class="px-3 py-2.5 text-right tabular-nums" :class="w.unattributed_amount ? 'font-semibold text-amber-700 dark:text-amber-300' : 'text-muted-foreground'">{{ w.uploaded ? formatCurrency(w.unattributed_amount ?? 0) : '—' }}</td>
                        <td class="px-3 py-2.5"><span class="rounded-full px-2 py-0.5 text-[10px] font-bold" :class="status(w).class">{{ status(w).label }}</span></td>
                        <td class="px-3 py-2.5 text-right">
                            <div class="flex justify-end gap-1.5">
                                <button v-if="w.uploaded" type="button" class="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 font-semibold text-foreground transition hover:border-primary/40 hover:bg-primary/5" @click="toggle(w.week_number)">
                                    <Eye class="size-3.5" /> Desglose <ChevronDown class="size-3 transition" :class="expanded[w.week_number] ? 'rotate-180' : ''" />
                                </button>
                                <button v-if="canUpload && !w.uploaded && w.week_number <= currentWeek" type="button" class="inline-flex items-center gap-1 rounded-lg bg-primary px-2.5 py-1 font-semibold text-primary-foreground shadow-sm transition hover:-translate-y-0.5 hover:shadow" @click="emit('upload', w)">
                                    <Upload class="size-3.5" /> Cargar colocación
                                </button>
                                <button v-if="canUpload && w.uploaded && !w.from_branch_file" type="button" class="inline-flex items-center gap-1 rounded-lg border border-border px-2.5 py-1 font-semibold text-muted-foreground transition hover:border-amber-500/50 hover:text-amber-700" @click="emit('upload', w)">
                                    <RefreshCw class="size-3.5" /> Reemplazar
                                </button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="expanded[w.week_number]" class="border-t border-border bg-muted/20">
                        <td :colspan="isChild ? 6 : 8" class="px-4 py-3">
                            <div v-if="expanded[w.week_number]?.loading" class="flex items-center gap-2 text-muted-foreground"><Loader2 class="size-4 animate-spin" /> Cargando desglose…</div>
                            <p v-else-if="expanded[w.week_number]?.error" class="text-destructive">{{ expanded[w.week_number]?.error }}</p>
                            <div v-else-if="expanded[w.week_number]?.data" class="space-y-2 animate-in fade-in duration-200">
                                <p v-if="!expanded[w.week_number]?.data.rows.length" class="rounded-lg border border-dashed border-border bg-background px-3 py-2 text-muted-foreground">
                                    {{ expanded[w.week_number]?.data.upload?.declared_complete === true || expanded[w.week_number]?.data.upload?.coverage_status === 'full'
                                        ? 'Este gestor no aparece en el reporte completo de la semana: su colocación de la semana es $0.00.'
                                        : 'Este gestor no aparece en el reporte parcial de la semana: su colocación queda pendiente hasta cargar el reporte completo (no se registra en cero).' }}
                                </p>
                                <div class="grid grid-cols-1 gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                                    <div v-for="r in expanded[w.week_number]?.data.rows" :key="(r.employee_id ?? r.name)" class="flex items-center gap-2 rounded-lg border border-border bg-background px-2.5 py-1.5">
                                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-[9px] font-bold text-white" :class="r.attributed ? avatarTone(r.employee_id) : 'from-slate-400 to-slate-500'">{{ initialsOf(r.name) }}</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate font-semibold text-foreground">{{ r.name }}</p>
                                            <p class="text-[10px] text-muted-foreground">{{ r.movements }} mov.<template v-if="r.duplicates"> · {{ r.duplicates }} repetido(s) excluidos</template><template v-if="r.child_objective_id"> · con OKR individual</template><template v-if="!r.attributed"> · pendiente de atribución (no suma a ningún gestor)</template></p>
                                            <p v-if="r.pending_reason" class="text-[10px] text-amber-700 dark:text-amber-300" :title="r.pending_reason">{{ r.pending_reason }}</p>
                                        </div>
                                        <span class="font-bold tabular-nums">{{ formatCurrency(r.amount) }}</span>
                                    </div>
                                </div>
                                <p class="text-[11px] text-muted-foreground">
                                    Excluidos — otra sucursal: {{ expanded[w.week_number]?.data.upload.rows_other_branch ?? 0 }} · cancelados: {{ expanded[w.week_number]?.data.upload.rows_cancelled ?? 0 }} · fuera del plazo: {{ expanded[w.week_number]?.data.upload.rows_out_of_objective_range ?? 0 }} · repetidos de semanas previas: {{ expanded[w.week_number]?.data.upload.rows_duplicated ?? 0 }}.
                                    Cargado por {{ expanded[w.week_number]?.data.upload.uploaded_by ?? '—' }}.
                                </p>
                            </div>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
        <button v-if="hiddenCount > 0" type="button" class="w-full border-t border-border py-2 text-xs font-semibold text-primary transition hover:bg-primary/5" @click="showAll = !showAll">
            {{ showAll ? 'Ocultar semanas futuras' : 'Ver las ' + hiddenCount + ' semanas restantes del periodo' }}
        </button>
    </div>
</template>
