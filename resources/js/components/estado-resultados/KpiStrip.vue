<script setup lang="ts">
/**
 * KpiStrip — cabecera de resultados del Estado de Resultados.
 *
 * Se usa en pages/EstadoResultados/Index. Contiene la ecuación Ingresos − OPEX
 * = EBITDA (EquationTerm), el reparto de cada $100 de ingresos (PerHundredBar),
 * los márgenes EBITDA, EBIT y neto, y el camino del EBITDA a la utilidad neta
 * (BottomLine), con variaciones contra el periodo comparado.
 *
 * Props:
 * - dataset: datos del reporte.
 * - activeStep: paso de la cascada seleccionado (o null).
 * - comparison: comparativa contra el periodo anterior (puede no estar lista).
 * - comparisonLoading: la comparativa se está cargando.
 *
 * Emits:
 * - select: se eligió un paso de la cascada (clave del paso).
 */
import { Info } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import BottomLine from './BottomLine.vue';
import type { CompPair } from './comparativas';
import { CONCEPT_HELP } from './conceptHelp';
import EquationTerm from './EquationTerm.vue';
import PerHundredBar from './PerHundredBar.vue';
import { isZero } from './types';
import type { Dataset } from './types';

/**
 * Cabecera del resultado: la ecuación del modelo (Ingresos − OPEX = EBITDA) en
 * grande, el reparto de cada $100 de ingresos y, a la derecha, el camino del
 * EBITDA a la utilidad neta. Todo es clicable y fija el paso en la cascada.
 */
const props = defineProps<{
    dataset: Dataset;
    activeStep: string | null;
    /** Comparativa contra el periodo anterior (carga aparte; puede no estar lista). */
    comparison?: CompPair | null;
    comparisonLoading?: boolean;
}>();
const emit = defineEmits<{ select: [stepKey: string] }>();

/** Valor del modelo vigente; las versiones con el modelo anterior usan sus claves equivalentes. */
function value(key: string, legacy?: string): string | null {
    const r = props.dataset.results;

    if (key in r) {
        return r[key] ?? null;
    }

    return legacy && legacy in r ? (r[legacy] ?? null) : null;
}

/** Ingresos operativos del periodo. */
const ingresos = computed(() => value('ingresos_operativos', 'ingresos'));
/** OPEX total del periodo. */
const opex = computed(() => value('opex_total'));
/** EBITDA del periodo. */
const ebitda = computed(() => value('ebitda', 'ebitda_final'));
/** Sin ingresos no hay resultado que leer: se dice «Sin datos», no $0. */
const noIncome = computed(
    () => ingresos.value === null || isZero(ingresos.value),
);
/** No hay OPEX (null o cero). */
const noOpex = computed(() => opex.value === null || isZero(opex.value));

/** Categorías de OPEX de la cascada; si no hay, una sola con el OPEX total. */
const categories = computed(() => {
    const cats = props.dataset.waterfall
        .filter((w) => w.group === 'opex')
        .map((w) => ({ key: w.key, label: w.label, amount: w.amount }));

    return cats.length
        ? cats
        : [{ key: 'opex_total', label: 'OPEX', amount: opex.value }];
});
/** Clave del primer paso de OPEX (el que se fija al hacer clic en OPEX). */
const firstOpex = computed(() => categories.value[0]?.key ?? 'opex_total');

/** Renglones de la comparativa indexados por clave. */
const rows = computed(
    () => new Map((props.comparison?.rows ?? []).map((r) => [r.key, r])),
);
// «Con reservas» también muestra la variación (marcada); sólo se oculta si no hay contra qué comparar.
const comparable = computed(() => {
    const c = props.comparison?.comparability;

    return c ? (c.level ? c.level !== 'no_comparable' : c.comparable) : false;
});
/** Etiqueta del periodo contra el que se compara. */
const against = computed(
    () => props.comparison?.comparison.period.label ?? null,
);
/** Motivo por el que no se puede comparar (para el tooltip). */
const notComparableWhy = computed(
    () =>
        props.comparison?.comparability.headline ??
        props.comparison?.comparability.reasons[0]?.message ??
        'Los periodos no se pueden comparar.',
);
/** La comparativa sigue cargando y aún no hay datos. */
const loading = computed(() => !!props.comparisonLoading && !props.comparison);

/** Márgenes EBITDA, EBIT, neto y (consolidado) después de retiros, con su paso, valor y texto cuando no hay valor. */
const margins = computed(() => {
    const m = props.dataset.margins;
    const noIsr = props.dataset.results.isr === null;
    const empty = (fallback: string) =>
        noIncome.value ? 'Sin ingresos' : fallback;

    const list = [
        {
            key: 'margen_ebitda',
            step: 'ebitda',
            label: 'Margen EBITDA',
            value: m.ebitda ?? null,
            empty: empty('Pendiente'),
        },
        {
            key: 'margen_ebit',
            step: 'ebit',
            label: 'Margen EBIT',
            value: m.ebit ?? null,
            empty: empty('Pendiente'),
        },
        {
            key: 'margen_neto',
            step: 'utilidad_neta',
            label: 'Margen neto',
            value: m.neto ?? null,
            empty: empty(noIsr ? 'Pendiente de ISR' : 'Pendiente'),
        },
    ];

    // Sólo consolidado: margen después de restar los retiros de DG y socios (rojo si es negativo).
    if (m.despues_retiros != null) {
        list.push({
            key: 'margen_despues_retiros',
            step: 'resultado_despues_retiros',
            label: 'Después de retiros',
            value: m.despues_retiros,
            empty: empty('Pendiente'),
        });
    }

    return list;
});

/** Explicación de por qué falta un margen según su texto vacío. */
const marginHint = (empty: string) =>
    empty === 'Sin ingresos'
        ? 'Sin ingresos en el periodo no se puede calcular un margen.'
        : empty === 'Pendiente de ISR'
          ? 'Se calcula cuando se capture el ISR del periodo.'
          : 'Falta información para calcularlo.';

/** Reenvía al padre el paso seleccionado. */
const select = (k: string) => emit('select', k);
</script>

<template>
    <section
        aria-label="Resultado del periodo"
        class="overflow-hidden rounded-2xl border bg-card shadow-soft-xs"
    >
        <!-- Encabezado: periodo, alcance y contra qué se compara -->
        <header
            class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b px-4 py-2.5 sm:px-5"
        >
            <h2 class="text-sm font-bold">
                Resultado de {{ dataset.meta.period.label }}
                <span class="font-medium text-muted-foreground"
                    >· {{ dataset.meta.scope.label }}</span
                >
            </h2>
            <p class="text-xs text-muted-foreground">
                <template v-if="comparison && comparable">
                    Flechas: cambio contra
                    <b class="text-foreground">{{ against }}</b>
                </template>
                <Tooltip v-else-if="comparison">
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1 rounded text-muted-foreground transition hover:text-foreground focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none"
                        >
                            <Info class="size-3.5" />
                            No se compara contra {{ against }}
                        </button>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">{{
                        notComparableWhy
                    }}</TooltipContent>
                </Tooltip>
                <template v-else-if="comparisonLoading"
                    >Buscando el periodo anterior…</template
                >
            </p>
        </header>

        <div
            class="grid lg:grid-cols-[minmax(0,1fr)_minmax(320px,380px)] 2xl:grid-cols-[minmax(0,1fr)_420px]"
        >
            <!-- Columna principal: ecuación, reparto por cada $100 y márgenes -->
            <div class="flex min-w-0 flex-col gap-5 p-3 sm:p-5">
                <!-- La ecuación del modelo -->
                <div
                    class="flex flex-wrap items-end gap-x-1 gap-y-2 sm:gap-x-3"
                    role="group"
                    aria-label="Ingresos operativos menos OPEX igual a EBITDA"
                >
                    <EquationTerm
                        label="Ingresos operativos"
                        :help="CONCEPT_HELP.ingresos_operativos"
                        :value="noIncome ? null : ingresos"
                        empty-text="Sin datos"
                        empty-hint="No hay ingresos registrados en el periodo: revisa que la Radiografía del mes esté cargada (pestaña Fuentes)."
                        :active="activeStep === 'ingresos_operativos'"
                        :row="rows.get('ingresos_operativos')"
                        :comparable="comparable"
                        :loading="loading"
                        :against="against"
                        @select="select('ingresos_operativos')"
                    />
                    <span
                        class="mb-7 text-3xl font-light text-muted-foreground/60"
                        aria-hidden="true"
                        >−</span
                    >
                    <EquationTerm
                        label="OPEX"
                        :help="CONCEPT_HELP.opex_total"
                        :value="noOpex && noIncome ? null : opex"
                        :empty-text="noIncome ? 'Sin datos' : 'Pendiente'"
                        empty-hint="Todavía no hay gastos registrados en el periodo."
                        tone="cost"
                        :active="activeStep === firstOpex"
                        :row="rows.get('opex_total')"
                        :comparable="comparable"
                        :loading="loading"
                        :against="against"
                        @select="select(firstOpex)"
                    />
                    <!-- «=» viaja con el EBITDA: en móvil baja junto con él -->
                    <div class="flex items-end gap-x-1 sm:gap-x-3">
                        <span
                            class="mb-7 text-3xl font-light text-muted-foreground/60"
                            aria-hidden="true"
                            >=</span
                        >
                        <EquationTerm
                            label="EBITDA"
                            :help="CONCEPT_HELP.ebitda"
                            :value="noIncome && noOpex ? null : ebitda"
                            empty-text="Sin datos"
                            empty-hint="Sin ingresos ni gastos registrados todavía no hay resultado que mostrar."
                            size="hero"
                            tone="result"
                            :active="activeStep === 'ebitda'"
                            :row="rows.get('ebitda')"
                            :comparable="comparable"
                            :loading="loading"
                            :against="against"
                            @select="select('ebitda')"
                        />
                    </div>
                </div>

                <!-- Reparto de cada $100 de ingresos (o aviso sin ingresos) -->
                <PerHundredBar
                    v-if="!noIncome"
                    :ingresos="ingresos"
                    :ebitda="ebitda"
                    :categories="categories"
                    :active="activeStep"
                    @select="select"
                />
                <p
                    v-else
                    class="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground"
                >
                    Sin ingresos registrados todavía, así que no hay reparto que
                    mostrar. Cuando se cargue la Radiografía del mes verás aquí
                    cuánto de cada $100 se va en gastos y cuánto queda.
                </p>

                <!-- Márgenes sobre ingresos -->
                <div
                    class="mt-auto grid gap-1 border-t pt-3 sm:gap-3"
                    :class="
                        margins.length > 3
                            ? 'grid-cols-2 sm:grid-cols-4'
                            : 'grid-cols-3'
                    "
                    role="group"
                    aria-label="Márgenes sobre ingresos"
                >
                    <EquationTerm
                        v-for="m in margins"
                        :key="m.key"
                        :label="m.label"
                        :help="CONCEPT_HELP[m.key]"
                        :value="m.value"
                        unit="%"
                        size="sm"
                        :empty-text="m.empty"
                        :empty-hint="marginHint(m.empty)"
                        :active="activeStep === m.step"
                        :row="rows.get(m.key)"
                        :comparable="comparable"
                        :loading="loading"
                        :against="against"
                        @select="select(m.step)"
                    />
                </div>
            </div>

            <!-- Columna derecha: del EBITDA a la utilidad neta -->
            <div
                class="border-t bg-muted/35 p-3 sm:p-5 lg:border-t-0 lg:border-l"
            >
                <BottomLine
                    :dataset="dataset"
                    :active="activeStep"
                    :rows="rows"
                    :comparable="comparable"
                    :loading="loading"
                    :against="against"
                    @select="select"
                />
            </div>
        </div>
    </section>
</template>
