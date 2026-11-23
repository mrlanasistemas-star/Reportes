<script setup lang="ts">
/**
 * ExpenseReviewTable — listado de gastos de la Revisión de gastos.
 *
 * Tabla en escritorio y tarjetas en móvil/tableta. Muestra concepto, categoría (y la anterior
 * si una regla la cambió), fecha, monto, sucursal, colaborador, destino en el reporte e
 * insignias de estado. Con permiso de edición permite marcar filas y la página completa.
 * Sin peticiones propias: emite eventos al padre (`ExpenseReviewStep`).
 */
import { PencilLine } from 'lucide-vue-next';
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import { money } from '@/lib/format';
import ExpenseStatusBadges from './ExpenseStatusBadges.vue';
import type { ExpenseRow } from './types';

/**
 * Props:
 * - `rows`: gastos de la página actual.
 * - `selected`: ids marcados.
 * - `allMatching`: `true` si la selección abarca todos los gastos del filtro (todas las filas se ven marcadas).
 * - `canEdit`: `true` si el usuario puede decidir (muestra casillas).
 */
const props = defineProps<{
    rows: ExpenseRow[];
    selected: Set<number>;
    allMatching: boolean;
    canEdit: boolean;
}>();

/**
 * Emits:
 * - `toggle(id, value)`: marcar/desmarcar un gasto.
 * - `toggle-page(value)`: marcar/desmarcar toda la página.
 * - `open(row)`: abrir el diálogo de decisión/detalle del gasto.
 */
const emit = defineEmits<{
    (e: 'toggle', id: number, value: boolean): void;
    (e: 'toggle-page', value: boolean): void;
    (e: 'open', row: ExpenseRow): void;
}>();

/** Estado de la casilla de cabecera: `true` (toda la página), `false` (ninguna) o `'indeterminate'` (parcial). */
const pageState = computed<boolean | 'indeterminate'>(() => {
    if (props.allMatching) {
        return true;
    }

    const n = props.rows.filter((r) => props.selected.has(r.id)).length;

    return n === 0 ? false : n === props.rows.length ? true : 'indeterminate';
});

/**
 * Indica si un gasto aparece marcado (por selección explícita o por «todos los del filtro»).
 *
 * @param id Id del gasto.
 * @returns `true` si está seleccionado.
 */
const isChecked = (id: number) => props.allMatching || props.selected.has(id);

/**
 * Convierte una fecha ISO `AAAA-MM-DD` a `DD/MM/AAAA`.
 *
 * @param d Fecha ISO o `null`.
 * @returns Fecha corta o «—» si no hay fecha.
 */
function shortDate(d: string | null) {
    if (!d) {
        return '—';
    }

    const [y, m, day] = d.split('-');

    return `${day}/${m}/${y}`;
}
</script>

<template>
    <!-- Escritorio -->
    <div
        class="hidden overflow-x-auto rounded-2xl border border-border bg-card lg:block"
    >
        <table class="w-full text-sm">
            <thead
                class="bg-muted/50 text-left text-xs font-semibold text-muted-foreground"
            >
                <tr>
                    <th v-if="canEdit" class="w-10 px-3 py-2.5">
                        <Checkbox
                            :model-value="pageState"
                            aria-label="Seleccionar la página"
                            @update:model-value="
                                (v) => emit('toggle-page', v === true)
                            "
                        />
                    </th>
                    <th class="px-3 py-2.5">Concepto</th>
                    <th class="px-3 py-2.5">Fecha</th>
                    <th class="px-3 py-2.5 text-right">Monto</th>
                    <th class="px-3 py-2.5">Sucursal</th>
                    <th class="px-3 py-2.5">Colaborador</th>
                    <th class="px-3 py-2.5">Destino en el reporte</th>
                    <th class="w-12 px-3 py-2.5">
                        <span class="sr-only">Acciones</span>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr
                    v-for="row in rows"
                    :key="row.id"
                    class="align-top transition hover:bg-muted/30"
                    :class="isChecked(row.id) ? 'bg-primary/5' : ''"
                >
                    <td v-if="canEdit" class="px-3 py-3">
                        <Checkbox
                            :model-value="isChecked(row.id)"
                            :aria-label="`Seleccionar ${row.concept ?? 'gasto'}`"
                            @update:model-value="
                                (v) => emit('toggle', row.id, v === true)
                            "
                        />
                    </td>
                    <td class="max-w-xs px-3 py-3">
                        <p class="font-semibold text-foreground">
                            {{ row.concept || 'Sin concepto' }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ row.category || 'Sin categoría' }} ·
                            {{ row.source_label }}
                            <span
                                v-if="row.category_before_rule"
                                class="text-info"
                                >(antes: {{ row.category_before_rule }})</span
                            >
                        </p>
                        <p
                            v-if="row.observations"
                            class="mt-1 line-clamp-2 text-xs text-muted-foreground"
                            :title="row.observations"
                        >
                            {{ row.observations }}
                        </p>
                    </td>
                    <td
                        class="px-3 py-3 whitespace-nowrap text-muted-foreground tabular-nums"
                    >
                        {{ shortDate(row.date) }}
                    </td>
                    <td
                        class="px-3 py-3 text-right font-semibold whitespace-nowrap text-foreground tabular-nums"
                    >
                        {{ money(row.amount) }}
                    </td>
                    <td class="px-3 py-3">
                        <p class="text-foreground">
                            {{ row.branch?.name ?? '—' }}
                        </p>
                    </td>
                    <td class="px-3 py-3">
                        <p class="text-foreground">
                            {{ row.employee?.name ?? '—' }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ row.attribution_label }}
                        </p>
                    </td>
                    <td class="max-w-[14rem] px-3 py-3">
                        <p
                            class="text-xs font-medium text-foreground"
                            :title="row.classification.hint ?? ''"
                        >
                            {{ row.classification.label }}
                        </p>
                        <ExpenseStatusBadges :row="row" class="mt-1.5" />
                    </td>
                    <td class="px-3 py-3 text-right">
                        <button
                            type="button"
                            class="inline-flex size-8 items-center justify-center rounded-lg border border-border text-muted-foreground transition hover:border-primary/40 hover:text-primary focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/20"
                            :aria-label="
                                canEdit
                                    ? `Decidir sobre ${row.concept ?? 'gasto'}`
                                    : `Ver detalle de ${row.concept ?? 'gasto'}`
                            "
                            @click="emit('open', row)"
                        >
                            <PencilLine class="size-4" aria-hidden="true" />
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Móvil / tableta -->
    <ul class="space-y-2 lg:hidden">
        <li
            v-for="row in rows"
            :key="row.id"
            class="rounded-2xl border bg-card p-4"
            :class="
                isChecked(row.id)
                    ? 'border-primary/50 bg-primary/5'
                    : 'border-border'
            "
        >
            <div class="flex items-start gap-3">
                <Checkbox
                    v-if="canEdit"
                    class="mt-1"
                    :model-value="isChecked(row.id)"
                    :aria-label="`Seleccionar ${row.concept ?? 'gasto'}`"
                    @update:model-value="
                        (v) => emit('toggle', row.id, v === true)
                    "
                />
                <button
                    type="button"
                    class="min-w-0 flex-1 text-left"
                    @click="emit('open', row)"
                >
                    <div class="flex items-start justify-between gap-2">
                        <p class="font-semibold text-foreground">
                            {{ row.concept || 'Sin concepto' }}
                        </p>
                        <p
                            class="shrink-0 font-semibold text-foreground tabular-nums"
                        >
                            {{ money(row.amount) }}
                        </p>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        {{ shortDate(row.date) }} ·
                        {{ row.category || 'Sin categoría' }} ·
                        {{ row.source_label }}
                    </p>
                    <p class="mt-1 text-xs text-foreground">
                        {{ row.branch?.name ?? 'Sin sucursal' }} ·
                        {{ row.employee?.name ?? 'Sin colaborador' }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ row.classification.label }}
                    </p>
                    <ExpenseStatusBadges :row="row" class="mt-2" />
                </button>
            </div>
        </li>
    </ul>
</template>
