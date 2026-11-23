<script setup lang="ts">
/**
 * ExpenseReviewPagination — paginación del listado de Revisión de gastos.
 *
 * Muestra el total de gastos y la página actual, permite cambiar el tamaño de página
 * (25/50/100) y avanzar/retroceder. Sin peticiones propias: el composable `useExpenseReview`
 * recarga al cambiar los modelos.
 *
 * Props: `total` (gastos del filtro), `lastPage` (última página).
 * Modelos: `page` (página actual), `perPage` (gastos por página).
 */
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { num } from '@/lib/format';

/** Props: total de gastos del filtro y número de la última página. */
const props = defineProps<{ total: number; lastPage: number }>();
/** Página actual (v-model:page). */
const page = defineModel<number>('page', { required: true });
/** Gastos por página (v-model:per-page). */
const perPage = defineModel<number>('perPage', { required: true });

/**
 * Cambia a la página indicada, limitada al rango [1, lastPage].
 *
 * @param p Página destino.
 */
const go = (p: number) => {
    page.value = Math.min(Math.max(1, p), props.lastPage);
};
</script>

<template>
    <nav
        class="flex flex-col items-center justify-between gap-3 text-sm sm:flex-row"
        aria-label="Paginación de gastos"
    >
        <p class="text-muted-foreground">
            {{ num(total) }} gasto(s) · página
            <span class="font-semibold text-foreground tabular-nums">{{
                page
            }}</span>
            de {{ lastPage }}
        </p>
        <div class="flex items-center gap-2">
            <NativeSelect
                v-model="perPage"
                class="w-24"
                aria-label="Gastos por página"
            >
                <NativeSelectOption :value="25">25</NativeSelectOption>
                <NativeSelectOption :value="50">50</NativeSelectOption>
                <NativeSelectOption :value="100">100</NativeSelectOption>
            </NativeSelect>
            <button
                type="button"
                class="app-btn app-btn-secondary h-9 px-2.5"
                :disabled="page <= 1"
                aria-label="Página anterior"
                @click="go(page - 1)"
            >
                <ChevronLeft class="size-4" aria-hidden="true" />
            </button>
            <button
                type="button"
                class="app-btn app-btn-secondary h-9 px-2.5"
                :disabled="page >= lastPage"
                aria-label="Página siguiente"
                @click="go(page + 1)"
            >
                <ChevronRight class="size-4" aria-hidden="true" />
            </button>
        </div>
    </nav>
</template>
