<script setup lang="ts">
/**
 * ExpenseReviewToolbar — barra de filtros del listado de Revisión de gastos.
 *
 * Pestañas de filtro (Pendientes, Sin colaborador, …), búsqueda libre, selector de fuente y
 * orden. Todo se controla por v-model; el composable `useExpenseReview` reacciona y recarga.
 */
import { Search } from 'lucide-vue-next';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import type { ReviewFilter } from './types';
import { FILTER_LABELS } from './types';

/** Props: `sources` = fuentes disponibles para filtrar (código y etiqueta). */
defineProps<{ sources: Array<{ code: string; label: string }> }>();

/** Filtro activo (v-model:filter). */
const filter = defineModel<ReviewFilter>('filter', { required: true });
/** Texto de búsqueda (v-model:search); el debounce lo aplica el composable. */
const search = defineModel<string>('search', { required: true });
/** Código de fuente seleccionada, `''` = todas (v-model:source). */
const source = defineModel<string>('source', { required: true });
/** Orden del listado (v-model:sort). */
const sort = defineModel<'monto' | 'fecha' | 'concepto'>('sort', {
    required: true,
});

/** Pares [filtro, etiqueta] para pintar las pestañas en orden. */
const filters = Object.entries(FILTER_LABELS) as Array<[ReviewFilter, string]>;
</script>

<template>
    <div class="flex flex-col gap-3">
        <!-- Pestañas de filtro -->
        <div
            class="flex flex-wrap gap-1.5"
            role="tablist"
            aria-label="Filtrar gastos"
        >
            <button
                v-for="[key, label] in filters"
                :key="key"
                type="button"
                role="tab"
                :aria-selected="filter === key"
                class="rounded-full border px-3 py-1 text-xs font-semibold transition focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/20"
                :class="
                    filter === key
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border bg-card text-muted-foreground hover:text-foreground'
                "
                @click="filter = key"
            >
                {{ label }}
            </button>
        </div>
        <!-- Búsqueda, fuente y orden -->
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="search"
                    type="search"
                    class="pl-9"
                    placeholder="Buscar por concepto, categoría, observación, colaborador o sucursal"
                    aria-label="Buscar gastos"
                />
            </div>
            <NativeSelect v-model="source" class="sm:w-44" aria-label="Fuente">
                <NativeSelectOption value=""
                    >Todas las fuentes</NativeSelectOption
                >
                <NativeSelectOption
                    v-for="s in sources"
                    :key="s.code"
                    :value="s.code"
                    >{{ s.label }}</NativeSelectOption
                >
            </NativeSelect>
            <NativeSelect v-model="sort" class="sm:w-40" aria-label="Ordenar">
                <NativeSelectOption value="monto"
                    >Mayor monto</NativeSelectOption
                >
                <NativeSelectOption value="fecha"
                    >Más recientes</NativeSelectOption
                >
                <NativeSelectOption value="concepto"
                    >Concepto (A–Z)</NativeSelectOption
                >
            </NativeSelect>
        </div>
    </div>
</template>
