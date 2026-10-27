<script setup lang="ts">
// Paso 3 — metas de UN alcance (sucursal o un gestor). Cada fila: línea base,
// incremento esperado, meta final = base + incremento, y peso. Todo valor
// capturado muestra su lectura formateada ($638,576.00) sin alterar el número
// que se guarda.
//
// Línea base (18-oct-2026):
//  · Sucursal → se consulta a Reportería (su base congelada).
//  · Gestor   → se SUGIERE automáticamente la base propia del colaborador desde
//    Reportería (filtrada por su employee_id, nunca la de la sucursal) y queda
//    PRECARGADA y EDITABLE; si no hay dato, se captura manual sin bloquear.
import { AlertTriangle, CheckCircle2, Loader2, PenLine, RefreshCw, Search, Sparkles, Undo2 } from 'lucide-vue-next'
import { onMounted, watch } from 'vue'
import OkrNumberInput from '@/components/okr/OkrNumberInput.vue'
import { useOkrBaselinePreview } from '@/composables/useOkrBaselinePreview'
import { formatByUnit } from '@/lib/okrFormat'
import { applyBaseline, applyIncrement, applyTarget, isAutomatic, kpiDirectionLabel, kpiIconFor, kpiTypeLabel, krGoalIsComplete } from './okrWizard'
import type { KrRow } from './okrWizard'

const props = defineProps<{
    kpis: any[]
    scope: { scope_type: 'branch' | 'employee'; branch_id: number | null; employee_id?: number | null; start_date: string }
    scopeLabel: string
}>()
const rows = defineModel<KrRow[]>('rows', { required: true })
const { consult, invalidate } = useOkrBaselinePreview()

function kpiOf(id: number | null) {
    return props.kpis.find((k) => k.id === id) ?? null
}

// Autosugerencia de la línea base del GESTOR (18-oct-2026): en cuanto la tabla
// aparece se busca la base propia del colaborador en Reportería, sin que el
// usuario pulse nada. Solo filas de gestor con KPI automático que NO estén bajo
// control manual: nunca pisa una base capturada/editada (así se conserva al
// reabrir "Editar metas") ni toca el alcance de sucursal (lo dispara el
// contenedor). Idempotente.
function autoSuggestEmployeeBaselines() {
    if (props.scope.scope_type !== 'employee') {
        return
    }

    rows.value.forEach((kr) => {
        const manuallyControlled = kr.baseline_mode === 'manual' || kr.baseline_mode === 'manual_authorized'

        // Nota: NO se condiciona a !kr.loading — al cambiar el contexto hay que
        // arrancar una consulta nueva aunque una anterior siga en vuelo; la
        // previa ya quedó invalidada (ver watch) y su respuesta se descarta.
        if (isAutomatic(kpiOf(kr.kpi_id)) && !manuallyControlled) {
            consult(kr, props.scope)
        }
    })
}

onMounted(autoSuggestEmployeeBaselines)
// Al cambiar el KPI de una fila o el alcance (gestor, sucursal, periodo):
// 1) INVALIDA de inmediato cualquier consulta en vuelo de TODAS las filas
//    —incluidas las manuales—, aunque kr.loading sea verdadero, para que una
//    respuesta del gestor/periodo anterior nunca se muestre ni se aplique;
// 2) relanza la sugerencia para las filas elegibles con el contexto nuevo.
watch(
    () => [rows.value.map((kr) => kr.kpi_id).join(','), props.scope.scope_type, props.scope.employee_id, props.scope.branch_id, props.scope.start_date].join('|'),
    () => {
        rows.value.forEach((kr) => invalidate(kr, props.scope))
        autoSuggestEmployeeBaselines()
    },
)

// Captura manual de la base: queda bajo control del usuario desde la primera
// tecla, así ninguna respuesta de Reportería en vuelo la reemplaza.
function onManualBaseline(kr: KrRow, value: string) {
    kr.baseline_mode = 'manual'
    applyBaseline(kr, value)
}

// Adopta explícitamente la base sugerida por Reportería SOBRE un valor escrito a
// mano — único camino para reemplazar una base manual (nunca en silencio).
function useSuggestion(kr: KrRow) {
    if (kr.preview?.available && kr.preview.value !== null && kr.preview.value !== undefined) {
        kr.baseline_mode = 'auto'
        applyBaseline(kr, String(kr.preview.value))
    }
}

// ¿Hay una sugerencia de Reportería distinta del valor manual actual del gestor?
// También si el usuario vació el campo: la sugerencia sigue a un clic.
function hasDifferentSuggestion(kr: KrRow): boolean {
    return (
        props.scope.scope_type === 'employee'
        && kr.baseline_mode === 'manual'
        && !!kr.preview?.available
        && kr.preview.value !== null
        && kr.preview.value !== undefined
        && String(kr.preview.value) !== String(kr.baseline_value)
    )
}

function incrementLabel(kr: KrRow): string {
    if (kr.increment === '') {
        return ''
    }

    const kpi = kpiOf(kr.kpi_id)
    const n = Number(kr.increment)
    const sign = n > 0 ? '+' : ''

    return kpi?.unit === 'percentage' ? `${sign}${n.toFixed(2)} pp` : `${sign}${formatByUnit(n, kpi?.unit)}`
}

function useManual(kr: KrRow) {
    kr.baseline_mode = 'manual_authorized'
    kr.baseline_value = ''
}

function cancelManual(kr: KrRow) {
    kr.baseline_mode = 'auto'
    kr.baseline_manual_reason = ''
    kr.baseline_value = kr.preview?.available && kr.preview.value !== null ? String(kr.preview.value) : ''
}
</script>

<template>
    <div class="overflow-x-auto rounded-xl border border-border">
        <table class="w-full min-w-[860px] text-xs">
            <thead class="border-b border-border bg-muted/30 text-muted-foreground">
                <tr>
                    <th class="px-2.5 py-2 text-left font-semibold">KPI</th>
                    <th class="min-w-[220px] px-2.5 py-2 text-left font-semibold">Línea base {{ scopeLabel }}</th>
                    <th class="min-w-[140px] px-2.5 py-2 text-left font-semibold">Incremento esperado</th>
                    <th class="min-w-[140px] px-2.5 py-2 text-left font-semibold">Meta final</th>
                    <th class="w-16 px-2.5 py-2 text-left font-semibold">Peso</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="kr in rows" :key="kr.client_key" class="border-t border-border align-top transition-colors hover:bg-muted/20">
                    <td class="px-2.5 py-3">
                        <div class="flex items-start gap-2">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full" :class="kpiIconFor(kpiOf(kr.kpi_id)).class">
                                <component :is="kpiIconFor(kpiOf(kr.kpi_id)).icon" class="size-3.5" />
                            </span>
                            <div class="min-w-0">
                                <p class="font-semibold text-foreground">{{ kpiOf(kr.kpi_id)?.name ?? '—' }}</p>
                                <p class="max-w-[180px] truncate text-[11px] text-muted-foreground">{{ kr.description }}</p>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <span class="rounded-full bg-violet-500/10 px-1.5 py-0.5 text-[9px] font-semibold text-violet-700 dark:text-violet-300">{{ kpiTypeLabel(kpiOf(kr.kpi_id)?.type) }}</span>
                                    <span class="rounded-full px-1.5 py-0.5 text-[9px] font-semibold" :class="kpiOf(kr.kpi_id)?.direction === 'decrease' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'">
                                        {{ kpiDirectionLabel(kpiOf(kr.kpi_id)?.direction) }} {{ kpiOf(kr.kpi_id)?.direction === 'decrease' ? '↘' : '↗' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </td>

                    <!-- LÍNEA BASE -->
                    <td class="px-2.5 py-3">
                        <!-- Gestor: base PRECARGADA automáticamente desde Reportería (su base
                             individual) y EDITABLE; si no hay dato, captura manual sin bloquear. -->
                        <div v-if="scope.scope_type === 'employee'" class="space-y-1 rounded-lg border p-2 transition" :class="kr.baseline_value === '' ? 'border-primary/40 bg-primary/5' : 'border-emerald-500/30 bg-emerald-500/5'">
                            <label :for="`baseline-${kr.client_key}`" class="block text-[10px] font-bold uppercase tracking-wide text-foreground">Línea base del gestor</label>

                            <div v-if="kr.loading" class="flex items-center gap-1 text-[10px] text-muted-foreground"><Loader2 class="size-3 animate-spin" /> Buscando la base del gestor en Reportería…</div>

                            <OkrNumberInput :id="`baseline-${kr.client_key}`" :model-value="kr.baseline_value" step="0.01" placeholder="0.00" class="h-8 rounded-lg text-xs" @update:model-value="(v) => onManualBaseline(kr, v)" />

                            <p v-if="kr.baseline_value !== ''" class="text-[10px] font-semibold tabular-nums text-foreground">= {{ formatByUnit(kr.baseline_value, kpiOf(kr.kpi_id)?.unit) }} · se congela al activar</p>

                            <!-- Ayuda discreta cuando la base se sugirió sola (y aún no se editó a mano). -->
                            <p v-if="!kr.loading && kr.preview?.available && kr.baseline_mode !== 'manual'" class="flex items-center gap-1 text-[10px] font-semibold text-emerald-700 dark:text-emerald-300">
                                <Sparkles class="size-3 shrink-0" /> Base sugerida automáticamente · se obtuvo desde Reportería<template v-if="kr.preview.period?.label"> · {{ kr.preview.period.label }}</template>
                            </p>

                            <!-- Sin dato automático: mensaje claro, captura manual sin bloquear. -->
                            <p v-else-if="!kr.loading && kr.preview && !kr.preview.available" class="flex items-start gap-1 text-[10px] text-amber-700 dark:text-amber-300">
                                <AlertTriangle class="mt-0.5 size-3 shrink-0" /> {{ kr.preview.message ?? kr.preview.error ?? 'No se encontró base automática para este gestor. Captúrala manualmente.' }}
                            </p>

                            <!-- Base manual capturada pero Reportería sugiere OTRA: se adopta solo con
                                 un clic explícito, nunca se reemplaza en silencio el valor del usuario. -->
                            <div v-if="!kr.loading && hasDifferentSuggestion(kr)" class="flex flex-wrap items-center gap-1.5 rounded-md bg-sky-500/10 px-2 py-1 text-[10px] text-sky-700 dark:text-sky-300">
                                <Sparkles class="size-3 shrink-0" />
                                <span>Reportería sugiere {{ formatByUnit(kr.preview?.value, kpiOf(kr.kpi_id)?.unit) }} para este gestor.</span>
                                <button type="button" class="font-semibold underline hover:no-underline" @click="useSuggestion(kr)">Usar base sugerida</button>
                            </div>

                            <p class="text-[10px] leading-snug text-muted-foreground">Es el valor inicial desde el cual se medirá su incremento durante este objetivo.</p>

                            <button v-if="!kr.loading && isAutomatic(kpiOf(kr.kpi_id)) && scope.branch_id" type="button" class="inline-flex items-center gap-1 text-[10px] font-semibold text-primary hover:underline" @click="consult(kr, scope)">
                                <RefreshCw class="size-3" /> Volver a consultar
                            </button>
                        </div>

                        <template v-else-if="isAutomatic(kpiOf(kr.kpi_id)) && kr.baseline_mode !== 'manual_authorized'">
                            <div v-if="kr.loading" class="space-y-1.5 rounded-lg border border-border p-2">
                                <div class="h-4 w-28 app-skeleton rounded bg-muted" />
                                <div class="flex items-center gap-1 text-[10px] text-muted-foreground"><Loader2 class="size-3 animate-spin" /> Consultando Reportería…</div>
                                <p class="text-[10px] text-muted-foreground/80">La primera consulta del periodo puede tardar hasta ~30 s; no cierres la ventana.</p>
                            </div>
                            <div v-else-if="kr.preview?.available" class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-2 animate-in fade-in duration-300">
                                <p class="flex items-center gap-1 text-sm font-bold tabular-nums text-foreground"><CheckCircle2 class="size-3.5 text-emerald-600" /> {{ formatByUnit(kr.preview.value, kpiOf(kr.kpi_id)?.unit) }}</p>
                                <p class="text-[10px] text-muted-foreground">Reportería · {{ kr.preview.period?.label ?? '—' }} · toda la sucursal</p>
                                <p class="text-[10px] text-muted-foreground">Se congela al activar el OKR.</p>
                                <button type="button" class="mt-1 inline-flex items-center gap-1 text-[10px] font-semibold text-primary hover:underline" @click="consult(kr, scope)"><RefreshCw class="size-3" /> Volver a consultar</button>
                            </div>
                            <div v-else-if="kr.preview" class="rounded-lg border border-amber-500/30 bg-amber-500/5 p-2 animate-in fade-in duration-300">
                                <p class="flex items-start gap-1 text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                                    {{ kr.preview.error ?? 'Reportería aún no tiene dato para esta sucursal.' }}
                                </p>
                                <p class="mt-0.5 text-[10px] text-muted-foreground">Puedes reintentar o capturar una base manual con motivo.</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    <button type="button" class="inline-flex items-center gap-1 text-[10px] font-semibold text-primary hover:underline" @click="consult(kr, scope)"><RefreshCw class="size-3" /> Reintentar</button>
                                    <button type="button" class="inline-flex items-center gap-1 text-[10px] font-semibold text-amber-700 hover:underline dark:text-amber-300" @click="useManual(kr)"><PenLine class="size-3" /> Capturar base manual</button>
                                </div>
                            </div>
                            <button v-else type="button" :disabled="!kr.kpi_id || !scope.branch_id" class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-primary/40 px-3 py-2 text-[11px] font-semibold text-primary transition hover:-translate-y-0.5 hover:bg-primary/5 hover:shadow-sm disabled:opacity-50" @click="consult(kr, scope)">
                                <Search class="size-3.5" /> Consultar línea base
                            </button>
                        </template>

                        <!-- Manual autorizada (KPI automático sin dato) -->
                        <div v-else-if="isAutomatic(kpiOf(kr.kpi_id))" class="space-y-1.5 rounded-lg border border-amber-500/40 bg-amber-500/5 p-2 animate-in fade-in duration-300">
                            <p class="text-[10px] font-bold uppercase tracking-wide text-amber-700 dark:text-amber-300">Línea base manual autorizada</p>
                            <OkrNumberInput :model-value="kr.baseline_value" step="0.01" placeholder="0.00" class="h-8 rounded-lg text-xs" :aria-label="`Línea base manual autorizada · ${kpiOf(kr.kpi_id)?.name ?? 'KPI'}`" @update:model-value="(v) => applyBaseline(kr, v)" />
                            <p v-if="kr.baseline_value !== ''" class="text-[10px] font-semibold tabular-nums text-foreground">= {{ formatByUnit(kr.baseline_value, kpiOf(kr.kpi_id)?.unit) }}</p>
                            <textarea v-model="kr.baseline_manual_reason" rows="2" maxlength="500" placeholder="Motivo (obligatorio, queda en bitácora)" class="app-textarea text-[11px]" />
                            <p v-if="kr.baseline_manual_reason.trim().length < 10" class="text-[10px] text-amber-700 dark:text-amber-300">Mínimo 10 caracteres.</p>
                            <button type="button" class="inline-flex items-center gap-1 text-[10px] font-semibold text-muted-foreground hover:text-foreground" @click="cancelManual(kr)"><Undo2 class="size-3" /> Volver a la consulta</button>
                        </div>

                        <!-- KPI manual -->
                        <div v-else>
                            <OkrNumberInput :model-value="kr.baseline_value" step="0.01" placeholder="0.00" class="h-9 rounded-lg text-xs" :aria-label="`Línea base ${scopeLabel} · ${kpiOf(kr.kpi_id)?.name ?? 'KPI'}`" @update:model-value="(v) => onManualBaseline(kr, v)" />
                            <p v-if="kr.baseline_value !== ''" class="mt-0.5 text-[10px] font-semibold tabular-nums text-muted-foreground">= {{ formatByUnit(kr.baseline_value, kpiOf(kr.kpi_id)?.unit) }}</p>
                        </div>
                    </td>

                    <td class="px-2.5 py-3">
                        <OkrNumberInput :model-value="kr.increment" step="0.01" placeholder="+0.00" class="h-9 rounded-lg text-xs" :aria-label="`Incremento esperado · ${kpiOf(kr.kpi_id)?.name ?? 'KPI'}`" @update:model-value="(v) => applyIncrement(kr, v)" />
                        <p v-if="kr.increment !== ''" class="mt-0.5 text-[10px] font-semibold tabular-nums" :class="Number(kr.increment) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">{{ incrementLabel(kr) }}</p>
                    </td>
                    <td class="px-2.5 py-3">
                        <OkrNumberInput :model-value="kr.target_value" step="0.01" placeholder="0.00" class="h-9 rounded-lg text-xs" :aria-label="`Meta final · ${kpiOf(kr.kpi_id)?.name ?? 'KPI'}`" @update:model-value="(v) => applyTarget(kr, v)" />
                        <p v-if="kr.target_value !== ''" class="mt-0.5 text-[11px] font-bold tabular-nums text-primary">{{ formatByUnit(kr.target_value, kpiOf(kr.kpi_id)?.unit) }}</p>
                    </td>
                    <td class="px-2.5 py-3">
                        <div class="flex items-center gap-1.5">
                            <span class="font-semibold tabular-nums text-foreground">{{ kr.weight }}%</span>
                            <CheckCircle2 v-if="krGoalIsComplete(kr, kpiOf(kr.kpi_id), scope.scope_type)" class="size-3.5 text-emerald-500 animate-in zoom-in duration-200" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
