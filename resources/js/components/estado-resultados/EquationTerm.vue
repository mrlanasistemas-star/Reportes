<script setup lang="ts">
/**
 * EquationTerm — término clicable de la ecuación del Estado de Resultados.
 *
 * Lo usa KpiStrip para pintar Ingresos, OPEX, EBITDA y los márgenes. Muestra
 * la cifra animada (formato compacto o porcentaje), un chip de variación
 * contra el periodo comparado (DeltaChip) y un tooltip con la explicación y
 * el monto exacto.
 *
 * Props:
 * - label / help: nombre del concepto y su explicación en palabras simples.
 * - value: monto como texto decimal; null = sin información (muestra emptyText).
 * - unit: 'MXN' (por defecto) o '%'.
 * - size: tamaño de la cifra ('hero', 'lg' por defecto o 'sm').
 * - tone: color de la cifra ('result', 'cost' o 'plain'); los negativos van en rojo.
 * - emptyText / emptyHint: texto y pista cuando no hay valor (por defecto "Pendiente").
 * - active: el término está seleccionado (resalta y marca aria-pressed).
 * - row / comparable / loading / against: datos para el chip de variación.
 *
 * Emits:
 * - select: al hacer clic; el padre fija ese paso en la cascada.
 */
import { computed, ref } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCountUp } from '@/composables/estado-resultados/useCountUp';
import type { CompRow } from './comparativas';
import DeltaChip from './DeltaChip.vue';
import { compact, isNegative, mxn, num, pct } from './types';

/**
 * Un término de la ecuación del resultado (Ingresos − OPEX = EBITDA) o un margen.
 * Es un botón: al hacer clic se fija el paso en la cascada de abajo. Al pasar el
 * cursor o enfocarlo explica el concepto en palabras simples.
 */
const props = defineProps<{
    label: string;
    help: string;
    /** null = sin información; se muestra `emptyText`, nunca $0. */
    value: string | null;
    unit?: 'MXN' | '%';
    size?: 'hero' | 'lg' | 'sm';
    tone?: 'result' | 'cost' | 'plain';
    emptyText?: string;
    emptyHint?: string;
    active?: boolean;
    row?: CompRow | null;
    comparable?: boolean;
    loading?: boolean;
    against?: string | null;
}>();

defineEmits<{ select: [] }>();

/** Unidad efectiva: MXN si no se indica. */
const unit = computed(() => props.unit ?? 'MXN');
/** Valor numérico animado (conteo progresivo hacia el valor real). */
const shown = useCountUp(() => num(props.value));
/** Texto grande mostrado: porcentaje con 2 decimales o monto compacto; null si no hay valor. */
const display = computed(() => {
    if (props.value === null) {
        return null;
    }

    return unit.value === '%'
        ? `${shown.value.toFixed(2)}%`
        : compact(shown.value);
});
/** Valor exacto formateado (moneda o porcentaje) para tooltip y lector de pantalla. */
const exact = computed(() =>
    props.value === null
        ? null
        : unit.value === '%'
          ? pct(props.value)
          : mxn(props.value),
);
/** Indica si el valor es negativo (se pinta en rojo). */
const negative = computed(
    () => props.value !== null && isNegative(props.value),
);
/** Referencia al DeltaChip para leer su texto hablado (`spoken`). */
const chip = ref<InstanceType<typeof DeltaChip> | null>(null);
/** Etiqueta accesible: concepto, valor exacto (o texto vacío) y la variación. */
const aria = computed(
    () =>
        `${props.label}: ${exact.value ?? `${props.emptyText ?? 'Pendiente'}. ${props.emptyHint ?? ''}`}${chip.value?.spoken ? `. ${chip.value.spoken}` : ''}`,
);

/** Clases de tamaño de letra según la prop `size`. */
const SIZE: Record<string, string> = {
    hero: 'text-[2.6rem] sm:text-5xl 2xl:text-[3.4rem]',
    lg: 'text-2xl sm:text-[1.9rem]',
    sm: 'text-xl',
};
</script>

<template>
    <!-- Botón del término con tooltip explicativo -->
    <Tooltip>
        <TooltipTrigger as-child>
            <button
                type="button"
                class="group relative flex min-w-0 flex-col items-start gap-1 rounded-xl px-2 py-1.5 text-left transition-colors hover:bg-muted/60 focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none"
                :class="active ? 'bg-cyan-50 dark:bg-cyan-950/30' : ''"
                :aria-pressed="active"
                :aria-label="aria"
                @click="$emit('select')"
            >
                <span
                    class="text-[13px] font-semibold text-muted-foreground decoration-dotted underline-offset-4 group-hover:underline"
                    >{{ label }}</span
                >
                <span
                    v-if="display !== null"
                    class="leading-none font-extrabold tracking-tight whitespace-nowrap tabular-nums"
                    :class="[
                        SIZE[size ?? 'lg'],
                        negative
                            ? 'text-rose-600 dark:text-rose-400'
                            : tone === 'result'
                              ? 'text-cyan-700 dark:text-cyan-300'
                              : tone === 'cost'
                                ? 'text-foreground/85'
                                : 'text-foreground',
                    ]"
                    >{{ display }}</span
                >
                <span
                    v-else
                    class="inline-flex w-fit items-center rounded-lg border border-dashed border-amber-400 px-2 py-0.5 font-bold text-amber-800 dark:border-amber-600 dark:text-amber-300"
                    :class="size === 'sm' ? 'text-sm' : 'text-lg'"
                    >{{ emptyText ?? 'Pendiente' }}</span
                >
                <span class="min-h-4">
                    <DeltaChip
                        v-if="value !== null"
                        ref="chip"
                        :row="row ?? null"
                        :comparable="!!comparable"
                        :loading="loading"
                        :against="against"
                    />
                </span>
            </button>
        </TooltipTrigger>
        <!-- Explicación del concepto y monto exacto -->
        <TooltipContent class="max-w-xs leading-snug">
            <p>{{ help }}</p>
            <p v-if="value === null && emptyHint" class="mt-1 opacity-80">
                {{ emptyHint }}
            </p>
            <p
                v-else-if="exact && unit === 'MXN'"
                class="mt-1 font-semibold tabular-nums"
            >
                {{ exact }}
            </p>
        </TooltipContent>
    </Tooltip>
</template>
