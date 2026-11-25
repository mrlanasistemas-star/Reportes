<script setup lang="ts">
/**
 * AmountPartitionBar — barra «¿A dónde va cada peso?» de un movimiento.
 *
 * La usa MovementDetailSheet. Reparte el importe bruto del movimiento en
 * conciliado con ERP, reconocido en el ER, excluido, pendiente y fuera del
 * periodo, con una barra proporcional y su leyenda con importes.
 *
 * Props:
 * - amounts: importes del movimiento por destino (incluye el bruto).
 */
import { computed } from 'vue';
import { mxn, num } from './types';
import type { MovementAmounts } from './types';

const props = defineProps<{ amounts: MovementAmounts }>();

/** Destinos posibles del importe, con su texto, ayuda y color de la barra. */
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
            <p class="text-[15px] font-extrabold">¿A dónde va cada peso?</p>
            <p class="text-sm text-muted-foreground">
                Bruto
                <b class="text-foreground tabular-nums">{{
                    mxn(amounts.gross)
                }}</b>
            </p>
        </div>
        <div
            class="mt-3 flex h-4 w-full gap-0.5 overflow-hidden rounded-full bg-muted"
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
                class="h-full transition-[width] duration-700 ease-out"
                :class="p.cls"
                :style="{ width: `${p.width}%` }"
                :title="`${p.label}: ${mxn(p.amount)}`"
            />
        </div>
        <ul class="mt-3 flex flex-wrap gap-2">
            <li
                v-for="p in parts.filter((x) => x.width > 0)"
                :key="p.key"
                class="inline-flex items-center gap-2 rounded-full border bg-background px-3 py-1 text-sm"
                :title="p.hint"
            >
                <span class="size-3 shrink-0 rounded-full" :class="p.cls" />
                <span>{{ p.label }}</span>
                <b class="tabular-nums">{{ mxn(p.amount) }}</b>
            </li>
            <li
                v-if="parts.some((x) => x.width <= 0)"
                class="inline-flex items-center rounded-full px-2 py-1 text-xs text-muted-foreground"
                :title="
                    parts
                        .filter((x) => x.width <= 0)
                        .map((p) => `${p.label}: ${mxn(p.amount)}`)
                        .join('\n')
                "
            >
                Resto de destinos en {{ mxn(0) }}
            </li>
        </ul>
    </div>
</template>
