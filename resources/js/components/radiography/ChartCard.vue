<script setup lang="ts">
/**
 * ChartCard — tarjeta con título y una gráfica de ApexCharts adaptada al tema.
 *
 * Se usa en el Dashboard, Reportes mensuales (Preview y ComparativePreview) y
 * en las pestañas de la radiografía. Muestra "Cargando…" o "Sin datos
 * disponibles para este periodo." cuando corresponde.
 *
 * Props:
 * - title / subtitle: encabezado de la tarjeta.
 * - type: tipo de gráfica (bar por defecto, donut, line, area, radialBar).
 * - height: alto en px (260 por defecto).
 * - series / options: datos y configuración de ApexCharts.
 * - loading: muestra el indicador de carga en lugar de la gráfica.
 */
import { computed } from 'vue';
import AsyncApexChart from '@/components/charts/AsyncApexChart.vue';
import { themedChartOptions, useChartDarkMode } from '@/lib/chart-theme';

const props = defineProps<{
    title: string;
    subtitle?: string;
    type?: 'bar' | 'donut' | 'line' | 'area' | 'radialBar';
    height?: number;
    series: any[];
    options: Record<string, any>;
    loading?: boolean;
}>();

/**
 * true si no hay series o si la suma de valores absolutos es 0 (soporta series
 * de donut —números— y de barras/líneas —{ name, data }—).
 */
const isEmpty = computed(() => {
    if (!props.series || props.series.length === 0) {
        return true;
    }

    const total = props.series.reduce((sum: number, s: any) => {
        // Donut/pie: la serie es number[]; cada elemento ES el valor
        if (typeof s === 'number') {
            return sum + Math.abs(s);
        }

        // Barras/líneas: la serie es [{ name, data: number[] }]
        const data = Array.isArray(s) ? s : (s?.data ?? []);

        return (
            sum +
            (data as number[]).reduce(
                (a: number, b: number) => a + Math.abs(Number(b) || 0),
                0,
            )
        );
    }, 0);

    return total === 0;
});

// ApexCharts no sigue el .dark de Tailwind solo: themedChartOptions() (única
// puerta, lib/chart-theme.ts) traduce los tokens del tema a colores explícitos
// por encima de lo que mande la página, sin tocar los colores de marca de cada
// serie. Reacciona al cambio de tema sin recargar.
/** true cuando la interfaz está en modo oscuro. */
const isDark = useChartDarkMode();
/** Opciones de la gráfica con los colores del tema aplicados. */
const themedOptions = computed(() =>
    themedChartOptions(props.options, isDark.value, props.type),
);
</script>

<template>
    <div
        class="rounded-2xl border bg-card p-5 text-card-foreground shadow-sm transition duration-200 hover:shadow-md"
    >
        <div class="mb-3">
            <h3
                class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
            >
                {{ title }}
            </h3>
            <p
                v-if="subtitle"
                class="mt-0.5 text-[11px] text-muted-foreground/80"
            >
                {{ subtitle }}
            </p>
        </div>
        <div
            v-if="loading"
            class="flex flex-col items-center justify-center gap-2 text-center text-xs text-muted-foreground"
            :style="{ height: (height ?? 260) + 'px' }"
            role="status"
        >
            <span
                aria-hidden="true"
                class="size-5 animate-spin rounded-full border-2 border-border border-t-primary"
            ></span>
            Cargando…
        </div>
        <div
            v-else-if="isEmpty"
            class="flex items-center justify-center text-center text-xs text-muted-foreground"
            :style="{ height: (height ?? 260) + 'px' }"
        >
            Sin datos disponibles para este periodo.
        </div>
        <AsyncApexChart
            v-else
            :type="type ?? 'bar'"
            :height="height ?? 260"
            :options="themedOptions"
            :series="series"
        />
    </div>
</template>
