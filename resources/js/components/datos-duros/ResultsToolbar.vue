<script setup lang="ts">
/** ResultsToolbar — conteo de resultados, orden por chips y cambio de vista tarjetas/tabla. */
import {
    ArrowDownWideNarrow,
    ArrowUpNarrowWide,
    LayoutGrid,
    LayoutList,
} from 'lucide-vue-next';
import type { HardDataSortKey } from '@/types/hardData';

defineProps<{
    count: number;
    total: number;
    sortKey: HardDataSortKey;
    sortDir: 'asc' | 'desc';
}>();
const view = defineModel<'cards' | 'table'>('view', { required: true });
const emit = defineEmits<{ sort: [key: HardDataSortKey] }>();

const SORTS: { key: HardDataSortKey; label: string }[] = [
    { key: 'ebitda', label: 'EBITDA' },
    { key: 'placement', label: 'Colocación' },
    { key: 'recovery', label: 'Recuperación' },
    { key: 'portfolio', label: 'Cartera' },
    { key: 'mora_pct', label: 'Mora' },
    { key: 'payroll', label: 'Nómina' },
    { key: 'name', label: 'A–Z' },
];
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <p class="text-sm text-muted-foreground">
            <span class="font-bold text-foreground tabular-nums">{{
                count
            }}</span>
            <template v-if="count !== total"> de {{ total }}</template>
            colaborador{{ count === 1 ? '' : 'es' }}
        </p>

        <div
            class="-mx-1 flex flex-1 items-center gap-1 overflow-x-auto px-1 [scrollbar-width:none] sm:justify-end [&::-webkit-scrollbar]:hidden"
        >
            <span
                class="mr-1 shrink-0 text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                >Ordenar</span
            >
            <button
                v-for="s in SORTS"
                :key="s.key"
                type="button"
                :aria-pressed="sortKey === s.key"
                :class="[
                    'inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold transition',
                    sortKey === s.key
                        ? 'bg-foreground text-background'
                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                ]"
                @click="emit('sort', s.key)"
            >
                {{ s.label }}
                <template v-if="sortKey === s.key">
                    <ArrowUpNarrowWide
                        v-if="sortDir === 'asc'"
                        class="size-3"
                        aria-label="ascendente"
                    />
                    <ArrowDownWideNarrow
                        v-else
                        class="size-3"
                        aria-label="descendente"
                    />
                </template>
            </button>
        </div>

        <div
            class="flex shrink-0 rounded-xl border border-border bg-card p-0.5"
            role="group"
            aria-label="Vista"
        >
            <button
                type="button"
                :aria-pressed="view === 'cards'"
                :class="[
                    'flex size-8 items-center justify-center rounded-lg transition',
                    view === 'cards'
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                title="Tarjetas"
                @click="view = 'cards'"
            >
                <LayoutGrid class="size-4" />
            </button>
            <button
                type="button"
                :aria-pressed="view === 'table'"
                :class="[
                    'flex size-8 items-center justify-center rounded-lg transition',
                    view === 'table'
                        ? 'bg-primary text-primary-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                ]"
                title="Tabla"
                @click="view = 'table'"
            >
                <LayoutList class="size-4" />
            </button>
        </div>
    </div>
</template>
