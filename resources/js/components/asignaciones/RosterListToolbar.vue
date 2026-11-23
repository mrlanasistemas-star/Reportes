<script setup lang="ts">
/**
 * RosterListToolbar — barra de búsqueda y filtros (categoría y sucursal) de las
 * listas del roster. Cada selector aparece solo si tiene más de una opción.
 *
 * Se usa en RosterOverviewPanel, PeriodMovementsPanel y PendingReconciliationPanel.
 *
 * Props:
 * - placeholder: texto del buscador (también su etiqueta accesible).
 * - categoryOptions: opciones del filtro de categoría (estado del movimiento).
 * - branchOptions: opciones del filtro de sucursal.
 *
 * v-model: query (búsqueda), category y branch ('' = todas).
 */
import { Search } from 'lucide-vue-next';
import SelectField from '@/components/forms/SelectField.vue';

/** Opción de un selector. */
type Option = { value: string; label: string };

withDefaults(
    defineProps<{
        placeholder?: string;
        categoryOptions?: Option[];
        branchOptions?: Option[];
    }>(),
    {
        placeholder: 'Buscar colaborador o sucursal...',
        categoryOptions: () => [],
        branchOptions: () => [],
    },
);

/** Texto de búsqueda. */
const query = defineModel<string>('query', { required: true });
/** Categoría seleccionada ('' = todas). */
const category = defineModel<string>('category', { default: '' });
/** Sucursal seleccionada ('' = todas). */
const branch = defineModel<string>('branch', { default: '' });
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <div class="relative w-full sm:w-64">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                aria-hidden="true"
            />
            <input
                v-model="query"
                type="search"
                autocomplete="off"
                class="app-input h-10 pl-10"
                :placeholder="placeholder"
                :aria-label="placeholder"
            />
        </div>
        <div v-if="categoryOptions.length > 1" class="w-full sm:w-60">
            <SelectField
                v-model="category"
                aria-label="Filtrar por categoría"
                :options="[
                    { value: '', label: 'Todas las categorías' },
                    ...categoryOptions,
                ]"
                placeholder="Todas las categorías"
            />
        </div>
        <div v-if="branchOptions.length > 1" class="w-full sm:w-52">
            <SelectField
                v-model="branch"
                aria-label="Filtrar por sucursal"
                :options="[
                    { value: '', label: 'Todas las sucursales' },
                    ...branchOptions,
                ]"
                placeholder="Todas las sucursales"
            />
        </div>
    </div>
</template>
