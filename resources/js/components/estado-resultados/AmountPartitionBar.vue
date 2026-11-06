<script setup lang="ts">
import { computed } from 'vue';
import { mxn, num } from './types';
import type { MovementAmounts } from './types';

const props = defineProps<{ amounts: MovementAmounts }>();

const PARTS: Array<{
    key: keyof MovementAmounts;
    label: string;
    hint: string;
    cls: string;
}> = [
    {
        key: 'conciliated',
        label: 'Conciliado con ERP',
        hint: 'Ya lo reconoce el ERP: no se vuelve a sumar.',
        cls: 'bg-cyan-500',
    },
    {
        key: 'recognized',
        label: 'Reconocido en el ER',
        hint: 'Suma a una línea del Estado de Resultados.',
        cls: 'bg-emerald-600 dark:bg-emerald-500',
    },
    {
        key: 'excluded',
        label: 'Excluido con justificación',
        hint: 'Traspaso, capital, CAPEX, terceros u otro concepto fuera del resultado.',
        cls: 'bg-slate-500 dark:bg-slate-400',
    },
    {
        key: 'pending',
        label: 'Pendiente',
        hint: 'Aún sin clasificar: ni suma ni se excluye.',
        cls: 'bg-[repeating-linear-gradient(135deg,#f59e0b_0_5px,#fbbf24_5px_10px)]',
    },
    {
        key: 'out_of_period',
        label: 'Fuera del periodo',
        hint: 'Su fecha no pertenece a este periodo.',
        cls: 'bg-violet-500',
    },
];

/** Anchos proporcionales al bruto (sólo visual; los importes vienen del dataset). */
const parts = computed(() => {
    const gross = Math.abs(num(props.amounts.gross)) || 1;

    return PARTS.map((p) => ({
        ...p,
        amount: props.amounts[p.key],
        width: (Math.abs(num(props.amounts[p.key])) / gross) * 100,
    }));
});
</script>

<template>
    <div>
        <div class="flex items-baseline justify-between gap-2">
            <p class="text-sm font-extrabold">¿A dónde va cada peso?</p>
            <p class="text-xs text-muted-foreground">
                Bruto
                <b class="text-foreground tabular-nums">{{
                    mxn(amounts.gross)
                }}</b>
            </p>
        </div>
        <div
            class="mt-2 flex h-3.5 w-full gap-px overflow-hidden rounded-full bg-muted"
            role="img"
            :aria-label="
                parts
                    .filter((p) => p.width > 0)
                    .map((p) => `${p.label} ${mxn(p.amount)}`)
                    .join(', ')
            "
        >
            <span
                v-for="p in parts.filter((x) => x.width > 0)"
                :key="p.key"
                class="h-full transition-[width] duration-500"
                :class="p.cls"
                :style="{ width: `${p.width}%` }"
                :title="`${p.label}: ${mxn(p.amount)}`"
            />
        </div>
        <ul class="mt-2.5 grid grid-cols-1 gap-x-4 gap-y-1.5 sm:grid-cols-2">
            <li
                v-for="p in parts"
                :key="p.key"
                class="flex items-center gap-2 text-xs"
                :class="p.width > 0 ? '' : 'opacity-45'"
                :title="p.hint"
            >
                <span class="size-2.5 shrink-0 rounded-sm" :class="p.cls" />
                <span class="min-w-0 flex-1 truncate">{{ p.label }}</span>
                <span class="font-semibold tabular-nums">{{
                    mxn(p.amount)
                }}</span>
            </li>
        </ul>
    </div>
</template>
