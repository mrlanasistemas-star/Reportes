<script setup lang="ts">
// Card editable de Key Result para el wizard (sección U del pedido) — nunca
// una tabla plana. Muestra unidad junto a los campos numéricos (base/meta) y
// el peso siempre en %.
import { computed } from 'vue'
import { ArrowDown, ArrowUp, Trash2 } from 'lucide-vue-next'
import SelectField from '@/components/forms/SelectField.vue'
import TextField from '@/components/forms/TextField.vue'
import { Button } from '@/components/ui/button'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'

type KrRow = { kpi_id: number | null; description: string; baseline_value: string; target_value: string; weight: string }

const props = defineProps<{
    modelValue: KrRow
    index: number
    kpis: any[]
    removable: boolean
}>()

const emit = defineEmits<{ (e: 'update:modelValue', v: KrRow): void; (e: 'remove'): void }>()

const kpi = computed(() => props.kpis.find((k) => k.id === props.modelValue.kpi_id) ?? null)
const unitSymbol = computed(() => (kpi.value?.unit === 'currency' ? '$' : kpi.value?.unit === 'percentage' ? '%' : ''))

function set<K extends keyof KrRow>(key: K, value: KrRow[K]) {
    emit('update:modelValue', { ...props.modelValue, [key]: value })
}
</script>

<template>
    <div class="app-card space-y-4 p-5 transition-shadow hover:shadow-md">
        <div class="flex items-center justify-between">
            <p class="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-primary">
                Key Result #{{ index + 1 }}
            </p>
            <Button v-if="removable" type="button" variant="ghost" size="icon-sm" class="text-destructive hover:bg-destructive/10 hover:text-destructive" aria-label="Eliminar Key Result" @click="emit('remove')">
                <Trash2 class="size-4" />
            </Button>
        </div>

        <TextField
            :model-value="modelValue.description"
            label="Descripción del resultado"
            placeholder="Ej. Incrementar EBITDA de $630k a $750k"
            @update:model-value="(v) => set('description', String(v ?? ''))"
        />

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="space-y-1.5">
                <div class="flex items-center gap-1.5">
                    <label class="text-sm font-semibold text-foreground">KPI</label>
                    <OkrHelpTooltip text="El KPI define de dónde sale el número: automático (se calcula solo desde Reportería en cada recálculo) o manual (lo captura la persona responsable en cada check-in)." />
                </div>
                <SelectField
                    :model-value="modelValue.kpi_id !== null ? String(modelValue.kpi_id) : null"
                    placeholder="Selecciona un KPI"
                    :options="kpis.map((k) => ({ value: String(k.id), label: `${k.name} (${k.automation === 'automatic' ? 'automático' : 'manual'})` }))"
                    @update:model-value="(v) => set('kpi_id', Number(v))"
                />
            </div>

            <div v-if="kpi" class="flex items-end gap-2 pb-0.5">
                <span class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground">{{ kpi.type }}</span>
                <span
                    class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold"
                    :class="kpi.direction === 'increase' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'"
                >
                    <ArrowUp v-if="kpi.direction === 'increase'" class="size-3" />
                    <ArrowDown v-else class="size-3" />
                    {{ kpi.direction === 'increase' ? 'Incrementar' : 'Disminuir' }}
                </span>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="relative space-y-1.5">
                <div class="flex items-center gap-1.5">
                    <label class="text-sm font-semibold text-foreground">Línea base</label>
                    <OkrHelpTooltip text="Punto de partida contra el que se mide el avance. Si el KPI es automático y la dejas vacía, se autocompleta con el dato real de Reportería al activar el OKR." />
                </div>
                <div class="relative">
                    <span v-if="unitSymbol" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">{{ unitSymbol }}</span>
                    <input
                        :value="modelValue.baseline_value"
                        type="number" step="0.01"
                        placeholder="Auto al activar"
                        class="app-input"
                        :class="unitSymbol ? 'pl-7' : ''"
                        @input="(e) => set('baseline_value', (e.target as HTMLInputElement).value)"
                    >
                </div>
            </div>

            <div class="relative space-y-1.5">
                <label class="text-sm font-semibold text-foreground">Meta</label>
                <div class="relative">
                    <span v-if="unitSymbol" class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">{{ unitSymbol }}</span>
                    <input
                        :value="modelValue.target_value"
                        type="number" step="0.01"
                        placeholder="0.00"
                        class="app-input"
                        :class="unitSymbol ? 'pl-7' : ''"
                        @input="(e) => set('target_value', (e.target as HTMLInputElement).value)"
                    >
                </div>
            </div>

            <div class="relative space-y-1.5">
                <div class="flex items-center gap-1.5">
                    <label class="text-sm font-semibold text-foreground">Peso</label>
                    <OkrHelpTooltip text="Qué tanto pesa este Key Result dentro del Objective. La suma de todos los KR debe dar exactamente 100%." />
                </div>
                <div class="relative">
                    <input
                        :value="modelValue.weight"
                        type="number" step="0.01" min="0.01" max="100"
                        placeholder="0.00"
                        class="app-input pr-8"
                        @input="(e) => set('weight', (e.target as HTMLInputElement).value)"
                    >
                    <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">%</span>
                </div>
            </div>
        </div>
    </div>
</template>
