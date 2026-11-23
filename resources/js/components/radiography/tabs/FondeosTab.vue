<script setup lang="ts">
/**
 * FondeosTab — pestaña "Fondeos / Excedentes" de la radiografía (ReportesMensuales/Preview).
 *
 * Muestra KPIs de fondeos entre sucursales y excedentes a corporativo, una
 * dona por sucursal de origen, el excedente por sucursal y el detalle de cada
 * fondeo. Solo disponible en alcance general.
 *
 * Props:
 * - available: false = alcance no general (se muestra aviso).
 * - detalleTotal / excedenteTotal: totales de fondeos y excedentes.
 * - detalleRows: detalle de fondeos (fecha, origen, destino, responsable, monto).
 * - porOrigen + porOrigenSeries/porOrigenOptions: totales y gráfica por sucursal de origen.
 * - corpFundingRows: excedente enviado a corporativo por sucursal.
 */
// Pestaña «Fondeos / Excedentes» de la radiografía. Solo presentación: los
// datos, series y opciones llegan ya calculados desde Preview.vue.
import { Banknote, Landmark, Receipt } from 'lucide-vue-next';
import ChartCard from '@/components/radiography/ChartCard.vue';
import EmptyState from '@/components/radiography/EmptyState.vue';
import KpiCard from '@/components/radiography/KpiCard.vue';
import { money, num } from '@/lib/format';

defineProps<{
    /** Solo el alcance general puede atribuir fondeos (movimientos entre sucursales/corporativo). */
    available: boolean;
    detalleTotal: number;
    excedenteTotal: number;
    detalleRows: any[];
    porOrigen: any[];
    porOrigenSeries: any[];
    porOrigenOptions: Record<string, any>;
    corpFundingRows: any[];
}>();
</script>

<template>
    <div class="space-y-5">
        <EmptyState
            v-if="!available"
            title="No atribuible a este alcance"
            description="Los fondeos entre sucursales y los excedentes a corporativo son movimientos entre sucursales/corporativo — no se pueden atribuir a una sola sucursal ni a un colaborador individual."
        />
        <template v-else>
            <!-- KPIs fondeo -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <KpiCard
                    label="Fondeos entre sucursales"
                    :value="money(detalleTotal)"
                    :icon="Landmark"
                    tone="blue"
                />
                <KpiCard
                    label="Excedente enviado a corporativo"
                    :value="money(excedenteTotal)"
                    :icon="Banknote"
                    tone="amber"
                />
                <KpiCard
                    label="Total movimientos"
                    :value="num(detalleRows.length)"
                    :icon="Receipt"
                    tone="neutral"
                />
            </div>
            <p
                class="rounded-xl border border-blue-100 bg-blue-50 px-4 py-2.5 text-xs text-blue-700 dark:border-blue-500/15 dark:bg-blue-500/10 dark:text-blue-300"
            >
                Los fondeos entre sucursales son movimientos de liquidez —
                <strong>no afectan el EBITDA ni el OPEX</strong>. Los excedentes
                enviados a corporativo se muestran como dato informativo.
            </p>
            <!-- Gráfica fondeos por origen -->
            <div v-if="porOrigen.length" class="grid gap-4 lg:grid-cols-2">
                <ChartCard
                    title="Fondeos por sucursal origen"
                    :series="porOrigenSeries"
                    :options="porOrigenOptions"
                    type="donut"
                    :height="280"
                />
                <div
                    class="overflow-x-auto rounded-2xl border bg-card shadow-soft-xs"
                >
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/60 text-[11px] font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-4 py-3 text-left">
                                    Sucursal origen
                                </th>
                                <th class="px-4 py-3 text-right">
                                    Total fondeado
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="r in porOrigen"
                                :key="r.sucursal"
                                class="border-t hover:bg-muted"
                            >
                                <td class="px-4 py-2.5 font-bold">
                                    {{ r.sucursal }}
                                </td>
                                <td
                                    class="px-4 py-2.5 text-right font-semibold"
                                >
                                    {{ money(r.monto) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Excedentes por sucursal -->
            <div
                v-if="corpFundingRows.length"
                class="overflow-x-auto rounded-2xl border bg-card shadow-soft-xs"
            >
                <div
                    class="flex items-center justify-between border-b bg-amber-50/60 px-5 py-3 dark:bg-amber-500/10"
                >
                    <span
                        class="text-sm font-bold text-amber-800 dark:text-amber-300"
                        >Excedente enviado a corporativo por sucursal</span
                    >
                    <span
                        class="text-sm font-semibold text-amber-900 dark:text-amber-200"
                        >{{ money(excedenteTotal) }}</span
                    >
                </div>
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/60 text-[11px] font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 text-left">Sucursal</th>
                            <th class="px-4 py-3 text-right">Total enviado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in corpFundingRows"
                            :key="r.branch"
                            class="border-t hover:bg-muted"
                        >
                            <td class="px-4 py-2.5 font-bold">
                                {{ r.branch }}
                            </td>
                            <td
                                class="px-4 py-2.5 text-right font-semibold text-amber-700 dark:text-amber-300"
                            >
                                {{ money(r.total) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <!-- Detalle completo fondeos -->
            <div
                v-if="detalleRows.length"
                class="overflow-x-auto rounded-2xl border bg-card shadow-soft-xs"
            >
                <div
                    class="flex items-center justify-between border-b px-5 py-3"
                >
                    <span class="text-sm font-bold"
                        >Detalle de fondeos entre sucursales</span
                    >
                    <span class="text-sm font-semibold">{{
                        money(detalleTotal)
                    }}</span>
                </div>
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/60 text-[11px] font-semibold tracking-wide whitespace-nowrap text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 text-left">Fecha</th>
                            <th class="px-4 py-3 text-left">Origen</th>
                            <th class="px-4 py-3 text-left">Destino</th>
                            <th class="px-4 py-3 text-left">Responsable</th>
                            <th class="px-4 py-3 text-right">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(f, i) in detalleRows"
                            :key="i"
                            class="border-t hover:bg-muted"
                            :class="i % 2 === 0 ? 'bg-card' : 'bg-muted/50'"
                        >
                            <td class="px-4 py-2 text-xs text-muted-foreground">
                                {{ f.fecha ?? '—' }}
                            </td>
                            <td class="px-4 py-2 font-semibold">
                                {{ f.sucursal_origen ?? '—' }}
                            </td>
                            <td class="px-4 py-2">
                                {{ f.sucursal_destino ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-xs text-muted-foreground">
                                {{ f.responsable ?? '—' }}
                            </td>
                            <td class="px-4 py-2 text-right font-semibold">
                                {{ money(f.monto) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState
                v-if="!detalleRows.length && !corpFundingRows.length"
                title="Sin fondeos ni excedentes registrados"
                description="No hay movimientos de fondeo ni envíos a corporativo para este periodo."
            />
        </template>
    </div>
</template>
