<script setup lang="ts">
/**
 * CategoriaTab — pestaña "Categoría EBITDA" de la radiografía (ReportesMensuales/Preview).
 *
 * Con sucursales: dos donas (distribución por categoría y EBITDA por sucursal)
 * y una tabla por sucursal; al hacer clic en una fila se resalta esa sucursal.
 * Con alcance de colaborador: solo el EBITDA y la categoría del colaborador.
 *
 * Props:
 * - hasBranches: hay datos por sucursal.
 * - rows: filas por sucursal (recuperación, colocación, OPEX, nómina, EBITDA, categoría).
 * - isEmployeeScope: el reporte está acotado a un colaborador.
 * - employeeEbitda / employeeCategory: EBITDA y categoría del colaborador.
 * - categoriaSeries / categoriaOptions, ebitdaSeries / ebitdaOptions: datos de las gráficas.
 *
 * v-model:selectedBranch — sucursal resaltada ('' = ninguna).
 */
// Pestaña «Categoría EBITDA» de la radiografía. Solo presentación: filas,
// series, opciones y la categoría del colaborador llegan calculadas desde
// Preview.vue (mismos umbrales que RadiographyStyleHelper::ebitdaCategory()).
import ChartCard from '@/components/radiography/ChartCard.vue';
import EbitdaBadge from '@/components/radiography/EbitdaBadge.vue';
import EmptyState from '@/components/radiography/EmptyState.vue';
import { money } from '@/lib/format';

defineProps<{
    hasBranches: boolean;
    rows: any[];
    isEmployeeScope: boolean;
    employeeEbitda: number;
    employeeCategory: string;
    categoriaSeries: any[];
    categoriaOptions: Record<string, any>;
    ebitdaSeries: any[];
    ebitdaOptions: Record<string, any>;
}>();

/** Sucursal resaltada (filtro visual compartido con el resto del reporte). */
const selectedBranch = defineModel<string>('selectedBranch', {
    required: true,
});

/**
 * Resalta la sucursal o quita el resaltado si ya estaba seleccionada.
 *
 * @param nombre Nombre de la sucursal.
 */
function toggle(nombre: string) {
    selectedBranch.value = selectedBranch.value === nombre ? '' : nombre;
}
</script>

<template>
    <div class="space-y-5">
        <template v-if="hasBranches">
            <div class="grid gap-4 lg:grid-cols-3">
                <ChartCard
                    title="Distribución por categoría"
                    :series="categoriaSeries"
                    :options="categoriaOptions"
                    type="donut"
                    :height="280"
                    class="lg:col-span-1"
                />
                <ChartCard
                    title="EBITDA por sucursal"
                    :series="ebitdaSeries"
                    :options="ebitdaOptions"
                    type="donut"
                    :height="280"
                    class="lg:col-span-2"
                />
            </div>
            <div
                class="overflow-x-auto rounded-2xl border bg-card shadow-soft-xs"
            >
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/60 text-[11px] font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 text-left">Sucursal</th>
                            <th class="px-4 py-3 text-right">Recuperación</th>
                            <th class="px-4 py-3 text-right">Colocación</th>
                            <th class="px-4 py-3 text-right">OPEX</th>
                            <th class="px-4 py-3 text-right">Nómina</th>
                            <th class="px-4 py-3 text-right">EBITDA</th>
                            <th class="px-4 py-3 text-center">Categoría</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="b in rows"
                            :key="b.nombre"
                            class="cursor-pointer border-t hover:bg-muted"
                            :class="
                                selectedBranch === b.nombre ? 'bg-accent' : ''
                            "
                            @click="toggle(b.nombre)"
                        >
                            <td class="px-4 py-2.5 font-bold">
                                {{ b.nombre }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                {{ money(b.recuperacion) }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                {{ money(b.colocacion) }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                {{ money(b.gastos) }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                {{ money(b.nomina) }}
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-semibold"
                                :class="
                                    b.ebitda < 0
                                        ? 'text-red-700 dark:text-red-300'
                                        : 'text-emerald-700 dark:text-emerald-300'
                                "
                            >
                                {{ money(b.ebitda) }}
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <EbitdaBadge :categoria="b.categoria" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-muted-foreground italic">
                EBITDA = Utilidad bruta (intereses + impuestos + moratorios +
                comisión por apertura + cargos adicionales + excedentes + 30%
                Seguro CRECE) − Gastos Totales (OPEX + Nómina y Capital Humano).
                No incluye capital recuperado.
            </p>
        </template>
        <div
            v-else-if="isEmployeeScope"
            class="rounded-2xl border bg-card p-6 shadow-soft-xs"
        >
            <p
                class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
            >
                Categoría EBITDA del colaborador
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-4">
                <p
                    class="text-2xl font-semibold"
                    :class="
                        employeeEbitda < 0
                            ? 'text-red-700 dark:text-red-300'
                            : 'text-emerald-700 dark:text-emerald-300'
                    "
                >
                    {{ money(employeeEbitda) }}
                </p>
                <EbitdaBadge :categoria="employeeCategory" />
            </div>
            <p class="mt-3 text-xs text-muted-foreground">
                No se muestra la distribución de todas las sucursales bajo
                alcance de colaborador — solo su propia categoría, con los
                mismos umbrales que el reporte general.
            </p>
        </div>
        <EmptyState v-else title="Sin datos para calcular categoría EBITDA" />
    </div>
</template>
