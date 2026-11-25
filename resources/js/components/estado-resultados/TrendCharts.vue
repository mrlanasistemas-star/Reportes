<script setup lang="ts">
/**
 * TrendCharts — cuadrícula de gráficas de tendencia de Comparativas.
 *
 * Se usa en ComparativesPanel. Dibuja (cada una sólo si el backend la manda):
 * evolución de resultados (líneas), ingresos vs OPEX con EBITDA (columnas +
 * línea), márgenes (%) y composición del OPEX (dona + lista). Los meses sin
 * datos quedan como hueco, nunca como $0.
 *
 * Props:
 * - charts: datos de las gráficas (CompCharts) ya calculados por el backend.
 */
import { computed } from 'vue';
import type { CompCharts, Series } from './comparativas';
import ErChart from './ErChart.vue';
import { compact, mxn, num, pct } from './types';

const props = defineProps<{ charts: CompCharts }>();

/** Colores fijos por concepto (iguales en todas las gráficas). */
const COLOR: Record<string, string> = {
    ingresos_operativos: '#059669',
    opex_total: '#e11d48',
    ebitda: '#0891b2',
    ebit: '#0f766e',
    ebt: '#475569',
    utilidad_neta: '#065f46',
    retiros_dg_socios: '#d97706',
    resultado_despues_retiros: '#7c2d12',
    margen_ebitda: '#0891b2',
    margen_ebit: '#0f766e',
    margen_neto: '#065f46',
};
/** Paleta de la dona de composición del OPEX (se repite si hay más categorías). */
const CAT_COLORS = [
    '#059669',
    '#14b8a6',
    '#06b6d4',
    '#0284c7',
    '#64748b',
    '#a855f7',
];

/** null se conserva como hueco ("Sin datos"), nunca como 0. */
const toSeries = (list: Series[]) =>
    list.map((s) => ({
        name: s.label,
        data: s.data.map((v) => (v === null ? null : num(v))),
    }));

/** Eje Y en formato compacto de moneda. */
const moneyAxis = {
    labels: {
        formatter: (v: number) =>
            v === null || v === undefined ? '' : compact(v),
    },
};
/** Tooltip en moneda; null se muestra como «Sin datos». */
const moneyTooltip = {
    y: {
        formatter: (v: number | null) =>
            v === null || v === undefined ? 'Sin datos' : mxn(v),
    },
};

/** Datos de la gráfica de evolución de resultados. */
const evo = computed(() => props.charts.evolucion_resultados);
/** Eje desde 0 cuando no hay negativos (sin escalas engañosas). */
const floor = (list: Series[] | undefined) =>
    (list ?? []).every((x) => x.data.every((v) => v === null || num(v) >= 0))
        ? 0
        : undefined;

/** Opciones de ApexCharts para la evolución (líneas con línea de cero). */
const evoOptions = computed(() => ({
    chart: { type: 'line' },
    colors: (evo.value?.series ?? []).map((s) => COLOR[s.key] ?? '#64748b'),
    stroke: { width: 3, curve: 'straight' },
    markers: { size: 4 },
    xaxis: { categories: evo.value?.labels ?? [] },
    yaxis: { ...moneyAxis, min: floor(evo.value?.series) },
    tooltip: moneyTooltip,
    annotations: {
        yaxis: [{ y: 0, borderColor: '#94a3b8', strokeDashArray: 0 }],
    },
}));

/** Datos de ingresos vs OPEX (y la serie de resultado opcional). */
const inc = computed(() => props.charts.ingresos_vs_opex);
/** Series de ingresos/OPEX como columnas y, si existe, la de resultado como línea. */
const incSeries = computed(() => {
    if (!inc.value) {
        return [];
    }

    const bars = toSeries(inc.value.series).map((s) => ({
        ...s,
        type: 'column',
    }));
    const line = inc.value.result_series
        ? [{ ...toSeries([inc.value.result_series])[0], type: 'line' }]
        : [];

    return [...bars, ...line];
});
/**
 * Opciones de la gráfica combinada; el grosor [0, 0, 3] supone dos series de
 * columnas seguidas de la línea de EBITDA.
 */
const incOptions = computed(() => ({
    chart: { type: 'line', stacked: false },
    colors: [
        ...(inc.value?.series ?? []).map((s) => COLOR[s.key] ?? '#64748b'),
        COLOR.ebitda,
    ],
    stroke: { width: [0, 0, 3] },
    plotOptions: { bar: { columnWidth: '45%', borderRadius: 4 } },
    xaxis: { categories: inc.value?.labels ?? [] },
    yaxis: {
        ...moneyAxis,
        min: floor([
            ...(inc.value?.series ?? []),
            ...(inc.value?.result_series ? [inc.value.result_series] : []),
        ]),
    },
    tooltip: { ...moneyTooltip, shared: true, intersect: false },
}));

/** Datos de márgenes (%). */
const mar = computed(() => props.charts.margenes);
/** Opciones de la gráfica de márgenes; null se muestra como «Sin margen». */
const marOptions = computed(() => ({
    chart: { type: 'line' },
    colors: (mar.value?.series ?? []).map((s) => COLOR[s.key] ?? '#64748b'),
    stroke: { width: 3, curve: 'straight' },
    markers: { size: 4 },
    xaxis: { categories: mar.value?.labels ?? [] },
    yaxis: {
        min: floor(mar.value?.series),
        labels: {
            formatter: (v: number) =>
                v === null || v === undefined ? '' : `${v.toFixed(0)}%`,
        },
    },
    tooltip: {
        y: {
            formatter: (v: number | null) =>
                v === null || v === undefined ? 'Sin margen' : pct(v),
        },
    },
    annotations: { yaxis: [{ y: 0, borderColor: '#94a3b8' }] },
}));

/** Datos de composición del OPEX. */
const comp = computed(() => props.charts.composicion_opex);
/** Dona sólo si hay OPEX positivo; con total 0 no hay porcentajes que mostrar. */
const compItems = computed(() =>
    (comp.value?.items ?? []).filter((i) => num(i.amount) > 0),
);
/** Opciones de la dona (etiquetas, colores, % en las porciones). */
const compOptions = computed(() => ({
    labels: compItems.value.map((i) => i.label),
    colors: CAT_COLORS,
    legend: { position: 'bottom' },
    plotOptions: { pie: { donut: { size: '62%' } } },
    tooltip: { y: { formatter: (v: number) => mxn(v) } },
    dataLabels: { enabled: true, formatter: (v: number) => `${v.toFixed(1)}%` },
}));

/**
 * Meses cuyo estado es «sin_datos».
 * @param labels etiquetas de los meses.
 * @param status estado de cada mes, en el mismo orden.
 */
const missingMonths = (
    labels: string[] | undefined,
    status: string[] | undefined,
) => (labels ?? []).filter((_, i) => status?.[i] === 'sin_datos');
</script>

<template>
    <div class="grid gap-4 xl:grid-cols-2">
        <!-- Evolución de resultados -->
        <section
            v-if="evo"
            class="rounded-2xl border bg-card p-4 shadow-soft-xs"
        >
            <h3 class="text-sm font-extrabold">{{ evo.title }}</h3>
            <ErChart
                type="line"
                :height="290"
                :options="evoOptions"
                :series="toSeries(evo.series)"
                :label="evo.title"
            />
            <p
                v-if="missingMonths(evo.labels, evo.status).length"
                class="text-[11px] text-muted-foreground"
            >
                Sin datos (hueco en la línea, no $0):
                {{ missingMonths(evo.labels, evo.status).join(', ') }}.
            </p>
        </section>

        <!-- Ingresos vs OPEX y EBITDA -->
        <section
            v-if="inc"
            class="rounded-2xl border bg-card p-4 shadow-soft-xs"
        >
            <h3 class="text-sm font-extrabold">{{ inc.title }} y EBITDA</h3>
            <ErChart
                type="line"
                :height="290"
                :options="incOptions"
                :series="incSeries"
                :label="inc.title"
            />
        </section>

        <!-- Márgenes -->
        <section
            v-if="mar"
            class="rounded-2xl border bg-card p-4 shadow-soft-xs"
        >
            <h3 class="text-sm font-extrabold">{{ mar.title }}</h3>
            <ErChart
                type="line"
                :height="270"
                :options="marOptions"
                :series="toSeries(mar.series)"
                :label="mar.title"
            />
            <p class="text-[11px] text-muted-foreground">
                Sin margen cuando los ingresos del mes son $0.00 o el resultado
                está pendiente.
            </p>
        </section>

        <!-- Composición del OPEX -->
        <section
            v-if="comp"
            class="rounded-2xl border bg-card p-4 shadow-soft-xs"
        >
            <h3 class="text-sm font-extrabold">{{ comp.title }}</h3>
            <ErChart
                v-if="compItems.length"
                type="donut"
                :height="270"
                :options="compOptions"
                :series="compItems.map((i) => num(i.amount))"
                :label="comp.title"
            />
            <p v-else class="py-10 text-center text-sm text-muted-foreground">
                Sin OPEX en el periodo: no hay composición que mostrar.
            </p>
            <ul class="mt-2 space-y-1 text-xs">
                <li
                    v-for="i in comp.items"
                    :key="i.key"
                    class="flex justify-between gap-2"
                >
                    <span>
                        {{ i.label }}
                        <span
                            v-if="i.centralized"
                            class="ml-1 rounded-full bg-slate-200 px-1.5 text-[10px] font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200"
                            >Centralizado</span
                        >
                    </span>
                    <span class="tabular-nums"
                        ><b>{{ mxn(i.amount) }}</b>
                        <span class="text-muted-foreground">
                            ·
                            {{
                                i.pct_of_opex === null
                                    ? '—'
                                    : pct(i.pct_of_opex)
                            }}</span
                        ></span
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
