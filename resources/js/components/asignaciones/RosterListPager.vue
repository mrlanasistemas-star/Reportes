<script setup lang="ts">
/**
 * RosterListPager — pie de paginación de las listas del roster: rango mostrado
 * ("1–8 de 40") y botones anterior/siguiente cuando hay más de una página.
 *
 * Se usa en RosterOverviewPanel, PeriodMovementsPanel y PendingReconciliationPanel.
 *
 * Props:
 * - total: registros totales tras los filtros.
 * - pageSize: registros por página.
 * - totalPages: total de páginas.
 *
 * v-model: página actual (empieza en 1).
 */
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    total: number;
    pageSize: number;
    totalPages: number;
}>();

/** Página actual (v-model). */
const page = defineModel<number>({ required: true });

/** Texto del rango visible, o "Sin registros" si la lista está vacía. */
const range = computed(() => {
    if (!props.total) {
        return 'Sin registros';
    }

    const start = (page.value - 1) * props.pageSize + 1;
    const end = Math.min(page.value * props.pageSize, props.total);

    return `${start}–${end} de ${props.total}`;
});
</script>

<template>
    <div
        class="flex items-center justify-between gap-3 border-t border-border px-4 py-2.5 sm:px-5"
    >
        <p class="text-xs text-muted-foreground tabular-nums">{{ range }}</p>
        <!-- Botones de navegación (solo con más de una página) -->
        <div v-if="totalPages > 1" class="flex items-center gap-1.5">
            <button
                type="button"
                class="inline-flex size-8 items-center justify-center rounded-lg border border-border text-muted-foreground transition hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                :disabled="page === 1"
                aria-label="Página anterior"
                @click="page--"
            >
                <ChevronLeft class="size-4" />
            </button>
            <span
                class="min-w-16 text-center text-xs font-semibold text-muted-foreground tabular-nums"
                >{{ page }} / {{ totalPages }}</span
            >
            <button
                type="button"
                class="inline-flex size-8 items-center justify-center rounded-lg border border-border text-muted-foreground transition hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                :disabled="page === totalPages"
                aria-label="Página siguiente"
                @click="page++"
            >
                <ChevronRight class="size-4" />
            </button>
        </div>
    </div>
</template>
