<script setup lang="ts">
/**
 * ProductosTab — pestaña "Productos" de la radiografía (ReportesMensuales/Preview).
 *
 * Dona de colocación por producto y tabla con operaciones, colocación,
 * recuperación y cartera; al hacer clic en una fila se resalta el producto.
 *
 * Props:
 * - hasRows: hay datos de producto.
 * - rows: filas por producto.
 * - chartSeries / chartOptions: datos de la gráfica.
 * - noDisponible: motivo a mostrar cuando no hay datos (null = mensaje por defecto).
 *
 * v-model:selectedProduct — producto resaltado ('' = ninguno).
 */
// Pestaña «Productos» de la radiografía. Solo presentación: los datos, series
// y opciones llegan ya calculados desde ReportesMensuales/Preview.vue.
import ChartCard from '@/components/radiography/ChartCard.vue';
import EmptyState from '@/components/radiography/EmptyState.vue';
import { money, num } from '@/lib/format';

defineProps<{
    hasRows: boolean;
    rows: any[];
    chartSeries: any[];
    chartOptions: Record<string, any>;
    noDisponible: string | null;
}>();

/** Producto resaltado (filtro visual compartido con el resto del reporte). */
const selectedProduct = defineModel<string>('selectedProduct', {
    required: true,
});

/** Formatea como moneda o muestra "—" si el valor no existe. */
const moneyOrDash = (v: unknown) =>
    v === null || v === undefined ? '—' : money(Number(v) || 0);

/**
 * Resalta el producto o quita el resaltado si ya estaba seleccionado.
 *
 * @param producto Nombre del producto.
 */
function toggle(producto: string) {
    selectedProduct.value = selectedProduct.value === producto ? '' : producto;
}
</script>

<template>
    <div class="space-y-5">
        <template v-if="hasRows">
            <ChartCard
                title="Colocación por producto"
                :series="chartSeries"
                :options="chartOptions"
                type="donut"
                :height="300"
            />
            <div
                class="overflow-x-auto rounded-2xl border bg-card shadow-soft-xs"
            >
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/60 text-[11px] font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 text-left">Producto</th>
                            <th class="px-4 py-3 text-right">Operaciones</th>
                            <th class="px-4 py-3 text-right">Colocación</th>
                            <th class="px-4 py-3 text-right">Recuperación</th>
                            <th class="px-4 py-3 text-right">Cartera</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="p in rows"
                            :key="p.producto"
                            class="cursor-pointer border-t hover:bg-muted"
                            :class="
                                selectedProduct === p.producto
                                    ? 'bg-accent'
                                    : ''
                            "
                            @click="toggle(p.producto)"
                        >
                            <td class="px-4 py-2.5 font-bold">
                                {{ p.producto }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                {{ num(p.operaciones) }}
                            </td>
                            <td class="px-4 py-2.5 text-right font-semibold">
                                {{ money(p.colocacion) }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                {{ moneyOrDash(p.recuperacion) }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                {{ moneyOrDash(p.cartera) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
        <EmptyState
            v-else
            title="Sin datos de producto"
            :description="
                noDisponible ??
                'Verifica que el archivo de ministraciones incluya la columna de producto financiero.'
            "
        />
    </div>
</template>
