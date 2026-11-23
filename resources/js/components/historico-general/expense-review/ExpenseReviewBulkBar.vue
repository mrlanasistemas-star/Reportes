<script setup lang="ts">
/**
 * ExpenseReviewBulkBar — barra flotante de acciones sobre la selección de gastos.
 *
 * Aparece cuando hay al menos un gasto seleccionado. Ofrece ampliar la selección a todos los
 * gastos del filtro (cuando la página completa está marcada y hay más resultados), quitar la
 * selección o abrir el diálogo de decisión masiva.
 */
import { ListChecks, X } from 'lucide-vue-next';
import { num } from '@/lib/format';

/**
 * Props:
 * - `count`: gastos seleccionados.
 * - `total`: gastos que cumplen el filtro actual.
 * - `allMatching`: `true` si la selección es «todos los del filtro».
 * - `pageCount`: filas visibles en la página actual.
 */
defineProps<{
    count: number;
    total: number;
    allMatching: boolean;
    pageCount: number;
}>();
/**
 * Emits:
 * - `decide`: abrir el diálogo de decisión para la selección.
 * - `select-all`: seleccionar todos los gastos del filtro.
 * - `clear`: quitar la selección.
 */
const emit = defineEmits<{
    (e: 'decide'): void;
    (e: 'select-all'): void;
    (e: 'clear'): void;
}>();
</script>

<template>
    <!-- Barra fija inferior: conteo de selección + acciones -->
    <div
        v-if="count > 0"
        class="sticky bottom-20 z-10 flex flex-col gap-3 rounded-2xl border border-primary/30 bg-card/95 px-4 py-3 shadow-soft-md backdrop-blur sm:flex-row sm:items-center sm:justify-between md:bottom-4"
        role="region"
        aria-label="Acciones sobre la selección"
    >
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <ListChecks class="size-4 text-primary" aria-hidden="true" />
            <span class="font-semibold text-foreground"
                >{{ num(count) }} gasto(s) seleccionado(s)</span
            >
            <button
                v-if="!allMatching && total > pageCount && count >= pageCount"
                type="button"
                class="text-xs font-semibold text-primary underline-offset-2 hover:underline"
                @click="emit('select-all')"
            >
                Seleccionar los {{ num(total) }} del filtro
            </button>
            <span v-if="allMatching" class="text-xs text-muted-foreground"
                >Incluye todos los gastos del filtro actual.</span
            >
        </div>
        <div class="flex gap-2">
            <button
                type="button"
                class="app-btn app-btn-secondary h-9"
                @click="emit('clear')"
            >
                <X class="size-4" aria-hidden="true" /> Quitar selección
            </button>
            <button
                type="button"
                class="app-btn app-btn-primary h-9"
                @click="emit('decide')"
            >
                Decidir para la selección
            </button>
        </div>
    </div>
</template>
