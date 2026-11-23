<script setup lang="ts">
/**
 * DeltaChip — etiqueta pequeña con la variación de una cifra contra el
 * periodo de comparación (flecha, importe compacto y %).
 *
 * La usan BottomLine y EquationTerm. Mientras carga muestra un esqueleto.
 *
 * Props:
 * - row: renglón de comparativa del concepto, o null.
 * - comparable: si los periodos son comparables (si no, no se pinta nada).
 * - loading: si la comparativa se está cargando.
 * - against: nombre corto del periodo contra el que se compara.
 *
 * Expone:
 * - spoken: texto completo de la variación (para lectores de pantalla).
 */
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-vue-next';
import { computed } from 'vue';
import { signed, signedCompact } from './comparativas';
import type { CompRow } from './comparativas';
import { num } from './types';

/**
 * Variación contra el periodo anterior de una cifra del resultado. El color sigue
 * la evaluación del backend (favorable / desfavorable), no el signo: que el OPEX
 * suba es desfavorable aunque la flecha apunte hacia arriba.
 * Si los periodos no son comparables no se pinta nada aquí: el aviso va una sola
 * vez en la cabecera del bloque.
 */
const props = defineProps<{
    row: CompRow | null;
    comparable: boolean;
    loading?: boolean;
    /** Nombre corto del periodo contra el que se compara (p. ej. «mayo»). */
    against?: string | null;
}>();

/** Dirección de la flecha según el signo de la variación (null si es 0 o no hay). */
const trend = computed(() => {
    const v = props.row?.variation;

    if (v === null || v === undefined || num(v) === 0) {
        return null;
    }

    return num(v) > 0 ? 'up' : 'down';
});

/** Texto completo para lectores de pantalla y el título nativo. */
const spoken = computed(() => {
    const r = props.row;

    if (!r || !props.comparable || r.variation === null) {
        return '';
    }

    const pctTxt =
        r.variation_pct !== null ? ` (${signed(r.variation_pct, '%')})` : '';

    return `${signed(r.variation, r.variation_unit)}${pctTxt} contra ${props.against ?? 'el periodo anterior'}${r.assessment_label ? `: ${r.assessment_label.toLowerCase()}` : ''}`;
});

defineExpose({ spoken });
</script>

<template>
    <span
        v-if="loading"
        class="inline-block h-3.5 w-20 animate-pulse rounded bg-muted"
        aria-hidden="true"
    />
    <span
        v-else-if="row && comparable && row.variation !== null"
        class="inline-flex items-center gap-0.5 text-xs font-semibold tabular-nums"
        :class="
            row.assessment === 'favorable'
                ? 'text-emerald-700 dark:text-emerald-400'
                : row.assessment === 'desfavorable'
                  ? 'text-rose-600 dark:text-rose-400'
                  : 'text-muted-foreground'
        "
        :title="spoken"
    >
        <ArrowUpRight v-if="trend === 'up'" class="size-3.5" />
        <ArrowDownRight v-else-if="trend === 'down'" class="size-3.5" />
        <Minus v-else class="size-3.5" />
        {{ signedCompact(row.variation, row.variation_unit) }}
        <span v-if="row.variation_pct !== null" class="font-medium opacity-75"
            >({{ signed(row.variation_pct, '%') }})</span
        >
    </span>
</template>
