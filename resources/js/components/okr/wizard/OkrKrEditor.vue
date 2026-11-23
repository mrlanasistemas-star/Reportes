<script setup lang="ts">
/**
 * OkrKrEditor — editor de Key Results reutilizable (paso 2 del asistente): el
 * MISMO componente edita los KR de la sucursal y los de cada gestor (cada uno
 * con su propio 100 %). Tipo/Dirección son filtros de UI sobre el catálogo
 * (nunca columnas nuevas). Permite reordenar filas arrastrándolas. Se usa en
 * OkrAssignDialog, OkrGestorGoalsCard y OkrCompleteKrDialog.
 *
 * Props:
 * - kpis: catálogo de KPI.
 * - placeholder: texto de ejemplo para la descripción del KR.
 *
 * v-model:
 * - rows: filas de KR (KrRow) que se editan.
 */
import { GripVertical, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import OkrNumberInput from '@/components/okr/OkrNumberInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ALL_FILTER, newKrRow, round2, totalWeight } from './okrWizard';
import type { KrRow } from './okrWizard';

const props = defineProps<{ kpis: any[]; placeholder?: string }>();
const rows = defineModel<KrRow[]>('rows', { required: true });

/** Suma de pesos actual. */
const total = computed(() => totalWeight(rows.value));

/**
 * Busca un KPI del catálogo por id.
 * @param id id del KPI.
 */
function kpiOf(id: number | null) {
    return props.kpis.find((k) => k.id === id) ?? null;
}

/** Agrega un KR con el peso que falta para llegar a 100 %. */
function addKr() {
    const remaining = Math.max(0, round2(100 - total.value));
    rows.value.push(
        newKrRow({ weight: remaining > 0 ? String(remaining) : '' }),
    );
}

/**
 * Elimina un KR.
 * @param i índice de la fila.
 */
function removeKr(i: number) {
    rows.value.splice(i, 1);
}

/** Un solo KR siempre pesa 100 %. */
watch(
    () => rows.value.length,
    (len) => {
        if (len === 1) {
            rows.value[0].weight = '100';
        }
    },
    { immediate: true },
);

/**
 * Peso máximo permitido para una fila (100 menos la suma de las demás).
 * @param i índice de la fila.
 */
function maxWeightForRow(i: number): number {
    const others = rows.value.reduce(
        (sum, kr, idx) => (idx === i ? sum : sum + (Number(kr.weight) || 0)),
        0,
    );

    return Math.max(0, round2(100 - others));
}

/**
 * Aplica el peso escrito, acotado entre 0 y el máximo de la fila.
 * @param kr fila editada.
 * @param i índice de la fila.
 * @param value valor emitido por OkrNumberInput.
 */
function onWeightInput(kr: KrRow, i: number, value: string) {
    if (value === '') {
        kr.weight = '';

        return;
    }

    // OkrNumberInput solo entrega números válidos ('' mientras el texto está a medias).
    const n = Number(value);
    const clamped = Math.min(Math.max(n, 0), maxWeightForRow(i));
    kr.weight = clamped === n ? value : String(clamped);
}

/**
 * Filtro de tipo vigente de la fila (el elegido o el tipo de su KPI); null = cualquiera.
 * @param kr fila de KR.
 */
function typeFilterOf(kr: KrRow): string | null {
    return kr.type_filter === ALL_FILTER
        ? null
        : (kr.type_filter ?? kpiOf(kr.kpi_id)?.type ?? null);
}

/**
 * Filtro de dirección vigente de la fila (el elegido o el de su KPI); null = cualquiera.
 * @param kr fila de KR.
 */
function directionFilterOf(kr: KrRow): string | null {
    return kr.direction_filter === ALL_FILTER
        ? null
        : (kr.direction_filter ?? kpiOf(kr.kpi_id)?.direction ?? null);
}

/**
 * KPI del catálogo que cumplen los filtros de tipo y dirección de la fila.
 * @param kr fila de KR.
 */
function kpisMatching(kr: KrRow) {
    const type = typeFilterOf(kr);
    const direction = directionFilterOf(kr);

    return props.kpis.filter(
        (k) =>
            (!type || k.type === type) &&
            (!direction || k.direction === direction),
    );
}

/**
 * Cambia un filtro de la fila y quita el KPI elegido si ya no coincide.
 * @param kr fila de KR.
 * @param key filtro a cambiar.
 * @param value nuevo valor del filtro.
 */
function onFilterChange(
    kr: KrRow,
    key: 'type_filter' | 'direction_filter',
    value: string,
) {
    kr[key] = value;

    if (kr.kpi_id && !kpisMatching(kr).some((k) => k.id === kr.kpi_id)) {
        kr.kpi_id = null;
    }
}

/**
 * Asigna el KPI a la fila; si cambia, limpia consulta, base, incremento y meta.
 * @param kr fila de KR.
 * @param id KPI elegido.
 */
function onKpiPick(kr: KrRow, id: number | null) {
    if (kr.kpi_id !== id) {
        // Cambiar de KPI invalida la consulta y los valores capturados.
        kr.preview = null;
        kr.baseline_value = '';
        kr.increment = '';
        kr.target_value = '';
        kr.baseline_mode = 'auto';
        kr.baseline_manual_reason = '';
    }

    kr.kpi_id = id;
    kr.type_filter = null;
    kr.direction_filter = null;
}

/** Índice de la fila que se está arrastrando. */
const dragIndex = ref<number | null>(null);

/**
 * Mueve la fila arrastrada a la posición donde se soltó. Soltarla sobre sí
 * misma no mueve nada, pero también termina el arrastre (antes la fila se
 * quedaba atenuada).
 * @param i índice destino.
 */
function onDropRow(i: number) {
    const from = dragIndex.value;
    dragIndex.value = null;

    if (from === null || from === i) {
        return;
    }

    const [moved] = rows.value.splice(from, 1);
    rows.value.splice(i, 0, moved);
}
</script>

<template>
    <div class="space-y-3">
        <div class="overflow-x-auto rounded-xl border border-border">
            <table class="w-full min-w-[760px] text-xs">
                <thead
                    class="border-b border-border bg-muted/30 text-muted-foreground"
                >
                    <tr>
                        <th class="w-10 px-2 py-2"></th>
                        <th class="px-2 py-2 text-left font-semibold">
                            Resultado clave
                        </th>
                        <th
                            class="min-w-[150px] px-2 py-2 text-left font-semibold"
                        >
                            KPI relacionado
                        </th>
                        <th
                            class="min-w-[130px] px-2 py-2 text-left font-semibold"
                        >
                            Tipo de KPI
                        </th>
                        <th
                            class="min-w-[130px] px-2 py-2 text-left font-semibold"
                        >
                            Dirección
                        </th>
                        <th class="w-24 px-2 py-2 text-left font-semibold">
                            Peso
                        </th>
                        <th class="w-8 px-2 py-2"></th>
                    </tr>
                </thead>
                <TransitionGroup
                    tag="tbody"
                    enter-active-class="transition duration-300"
                    enter-from-class="opacity-0 -translate-y-1"
                    leave-active-class="transition duration-150"
                    leave-to-class="opacity-0"
                >
                    <tr
                        v-for="(kr, i) in rows"
                        :key="kr.client_key"
                        class="border-t border-border transition-colors hover:bg-muted/30"
                        :class="dragIndex === i ? 'opacity-40' : ''"
                        draggable="true"
                        @dragstart="dragIndex = i"
                        @dragover.prevent
                        @drop="onDropRow(i)"
                        @dragend="dragIndex = null"
                    >
                        <td
                            class="px-2 py-2.5 text-center text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1"
                                ><GripVertical
                                    class="size-3.5 cursor-grab text-muted-foreground/70 hover:text-foreground"
                                />{{ i + 1 }}</span
                            >
                        </td>
                        <td class="min-w-[180px] px-2 py-2.5">
                            <Input
                                v-model="kr.description"
                                :aria-label="`Descripción del Key Result ${i + 1}`"
                                maxlength="191"
                                :placeholder="
                                    placeholder ??
                                    'Incrementar colocación de cartera'
                                "
                                class="h-9 rounded-lg text-xs"
                            />
                        </td>
                        <td class="px-2 py-2.5">
                            <Select
                                :model-value="
                                    kr.kpi_id !== null
                                        ? String(kr.kpi_id)
                                        : undefined
                                "
                                @update:model-value="
                                    (v) => onKpiPick(kr, v ? Number(v) : null)
                                "
                            >
                                <SelectTrigger
                                    size="sm"
                                    :aria-label="`KPI del Key Result ${i + 1}`"
                                    class="w-full justify-between gap-1 rounded-full border-0 bg-blue-500/10 px-3 text-[11px] font-semibold text-blue-700 transition hover:bg-blue-500/20 dark:text-blue-300"
                                >
                                    <SelectValue
                                        placeholder="Selecciona un KPI"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="k in kpisMatching(kr)"
                                        :key="k.id"
                                        :value="String(k.id)"
                                        >{{ k.name }}</SelectItem
                                    >
                                    <p
                                        v-if="kpisMatching(kr).length === 0"
                                        class="px-2 py-3 text-center text-[11px] text-muted-foreground"
                                    >
                                        Ningún KPI coincide con Tipo/Dirección.
                                    </p>
                                </SelectContent>
                            </Select>
                        </td>
                        <td class="px-2 py-2.5">
                            <Select
                                :model-value="typeFilterOf(kr) ?? ALL_FILTER"
                                @update:model-value="
                                    (v) =>
                                        onFilterChange(
                                            kr,
                                            'type_filter',
                                            v as string,
                                        )
                                "
                            >
                                <SelectTrigger
                                    size="sm"
                                    :aria-label="`Filtrar KPI por tipo (Key Result ${i + 1})`"
                                    class="w-full justify-between gap-1 rounded-full border-0 bg-violet-500/10 px-3 text-[11px] font-semibold text-violet-700 transition hover:bg-violet-500/20 dark:text-violet-300"
                                >
                                    <SelectValue placeholder="Tipo" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="ALL_FILTER"
                                        >Cualquiera</SelectItem
                                    >
                                    <SelectItem value="cumulative"
                                        >Acumulativo</SelectItem
                                    >
                                    <SelectItem value="balance"
                                        >Saldo</SelectItem
                                    >
                                    <SelectItem value="percentage"
                                        >Porcentual</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </td>
                        <td class="px-2 py-2.5">
                            <Select
                                :model-value="
                                    directionFilterOf(kr) ?? ALL_FILTER
                                "
                                @update:model-value="
                                    (v) =>
                                        onFilterChange(
                                            kr,
                                            'direction_filter',
                                            v as string,
                                        )
                                "
                            >
                                <SelectTrigger
                                    size="sm"
                                    :aria-label="`Filtrar KPI por dirección (Key Result ${i + 1})`"
                                    class="w-full justify-between gap-1 rounded-full border-0 px-3 text-[11px] font-semibold transition hover:opacity-80"
                                    :class="
                                        directionFilterOf(kr) === 'decrease'
                                            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                                            : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                    "
                                >
                                    <SelectValue placeholder="Dirección" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem :value="ALL_FILTER"
                                        >Cualquiera</SelectItem
                                    >
                                    <SelectItem value="increase"
                                        >Incrementar ↗</SelectItem
                                    >
                                    <SelectItem value="decrease"
                                        >Disminuir ↘</SelectItem
                                    >
                                </SelectContent>
                            </Select>
                        </td>
                        <td class="px-2 py-2.5">
                            <div class="relative">
                                <OkrNumberInput
                                    :model-value="kr.weight"
                                    step="0.01"
                                    min="0"
                                    :max="maxWeightForRow(i)"
                                    :disabled="rows.length === 1"
                                    placeholder="0"
                                    class="h-9 rounded-lg pr-6 text-xs disabled:cursor-not-allowed disabled:opacity-70"
                                    :aria-label="`Peso del Key Result ${i + 1}`"
                                    @update:model-value="
                                        (v) => onWeightInput(kr, i, v)
                                    "
                                />
                                <span
                                    class="pointer-events-none absolute top-1/2 right-2 -translate-y-1/2 text-[11px] text-muted-foreground"
                                    >%</span
                                >
                            </div>
                        </td>
                        <td class="px-2 py-2.5 text-center">
                            <button
                                v-if="rows.length > 1"
                                type="button"
                                class="rounded-full p-1 text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive"
                                :aria-label="`Eliminar Key Result ${i + 1}`"
                                @click="removeKr(i)"
                            >
                                <Trash2 class="size-3.5" aria-hidden="true" />
                            </button>
                        </td>
                    </tr>
                </TransitionGroup>
            </table>
        </div>

        <!-- Agregar KR y total de ponderación -->
        <div class="flex flex-wrap items-center justify-between gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="gap-1.5 rounded-xl border-dashed transition hover:border-primary hover:text-primary"
                @click="addKr"
            >
                <Plus class="size-3.5" /> Agregar Key Result
            </Button>
            <span
                class="rounded-full px-2.5 py-1 text-[11px] font-bold tabular-nums transition-colors"
                :class="
                    Math.abs(total - 100) < 0.01
                        ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                        : 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                "
            >
                Ponderación {{ total }}% / 100%
            </span>
        </div>
    </div>
</template>
