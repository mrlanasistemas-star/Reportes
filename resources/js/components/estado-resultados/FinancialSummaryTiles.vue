<script setup lang="ts">
/**
 * FinancialSummaryTiles — cabecera visual de «Gastos financieros».
 *
 * La usa FinancialExpensesPanel. Fila de KPI (debía pagar, aplicado con faltante,
 * costo financiero reconocido, IVA separado y efecto en EBT) y una barra que parte
 * el pago en capital / interés / comisión / IVA.
 *
 * Props:
 * - summary: resumen del periodo (FinancialExpenseService::panel → summary).
 * - scheduled: pago total según tablas (respaldo cuando aún no hay cargos observados).
 */
import { computed } from 'vue';
import type { FinancialSummary } from './financialExpenses';
import FinInfoTip from './FinInfoTip.vue';
import { mxn, num } from './types';

const props = defineProps<{ summary: FinancialSummary; scheduled: string }>();

/** Faltante total del periodo (para colorear el subtítulo del aplicado). */
const shortTotal = computed(() => props.summary.shortfall ?? '0');

/** Partes del pago; el ancho es proporcional a su suma (sólo visual). */
const parts = computed(() => {
    const s = props.summary;
    const list = [
        {
            key: 'capital',
            label: 'Capital',
            amount: s.principal_informative,
            cls: 'bg-slate-400 dark:bg-slate-500',
        },
        {
            key: 'interes',
            label: 'Interés',
            amount: s.interest_recognized,
            cls: 'bg-rose-600 dark:bg-rose-500',
        },
        {
            key: 'comision',
            label: 'Comisión',
            amount: s.commission_recognized,
            cls: 'bg-rose-400 dark:bg-rose-300',
        },
        {
            key: 'iva',
            label: 'IVA',
            amount: s.vat_separated,
            cls: 'bg-amber-400 dark:bg-amber-300',
        },
    ];
    const total = list.reduce((t, p) => t + Math.abs(num(p.amount)), 0) || 1;

    return list.map((p) => ({
        ...p,
        pct: (Math.abs(num(p.amount)) / total) * 100,
    }));
});

/** Tiles de la fila de KPI. */
const tiles = computed(() => {
    const s = props.summary;
    const short = num(s.shortfall);

    return [
        {
            key: 'debia',
            label: 'Debía pagar',
            value: s.expected_payment ?? props.scheduled,
            sub: '',
            cls: '',
            box: 'bg-card',
            help: 'Pagos de las tablas de amortización que caen en el periodo.',
        },
        {
            key: 'aplicado',
            label: 'Aplicado',
            value: s.applied_payment ?? s.observed_payment,
            sub: short > 0 ? `Faltan ${mxn(short)}` : 'Sin faltante',
            cls: 'text-emerald-700 dark:text-emerald-400',
            box: 'bg-card',
            help: 'Aportaciones de sucursales o pagos al acreedor, lo mayor (es el mismo dinero: no se suma dos veces).',
        },
        {
            key: 'costo',
            label: 'Costo financiero',
            value: s.financial_cost,
            sub: '',
            cls: 'text-rose-700 dark:text-rose-400',
            box: 'bg-card',
            help: `Interés ${mxn(s.interest_recognized)} + comisión ${mxn(s.commission_recognized)}.\nComisión financiera: criterio por confirmar con Finanzas.`,
        },
        {
            key: 'iva',
            label: 'IVA separado',
            value: s.vat_separated,
            sub: '',
            cls: 'text-amber-700 dark:text-amber-400',
            box: 'bg-card',
            help: 'IVA de intereses y comisiones: nunca suma al gasto.',
        },
        {
            key: 'ebt',
            label: 'Efecto en EBT',
            value: s.ebt_effect,
            sub: '',
            cls: 'text-rose-700 dark:text-rose-400',
            box: 'border-rose-200 bg-rose-50/60 dark:border-rose-900 dark:bg-rose-950/25',
            help: 'Lo que reduce el resultado antes de impuestos (una sola vez, en el consolidado).',
        },
    ];
});
</script>

<template>
    <section
        aria-label="Resumen de gastos financieros"
        class="space-y-3 rounded-2xl border bg-card p-4 shadow-soft-xs"
    >
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            <div
                v-for="t in tiles"
                :key="t.key"
                class="rounded-xl border p-3"
                :class="[
                    t.box,
                    t.key === 'ebt' ? 'col-span-2 sm:col-span-1' : '',
                ]"
            >
                <p
                    class="flex items-center gap-1 text-[11px] font-medium text-muted-foreground"
                >
                    {{ t.label }}
                    <FinInfoTip :text="t.help" :label="`Qué es ${t.label}`" />
                </p>
                <p
                    class="mt-0.5 truncate text-base font-extrabold tabular-nums sm:text-lg"
                    :class="t.cls"
                >
                    {{ mxn(t.value) }}
                </p>
                <p
                    v-if="t.sub"
                    class="text-xs font-semibold tabular-nums"
                    :class="
                        num(shortTotal) > 0
                            ? 'text-rose-700 dark:text-rose-400'
                            : 'text-muted-foreground'
                    "
                >
                    {{ t.sub }}
                </p>
            </div>
        </div>

        <div>
            <div
                class="flex h-3 w-full gap-px overflow-hidden rounded-full bg-muted"
                role="img"
                :aria-label="
                    parts.map((p) => `${p.label} ${mxn(p.amount)}`).join(', ')
                "
            >
                <span
                    v-for="p in parts.filter((x) => x.pct > 0)"
                    :key="p.key"
                    class="h-full transition-[width] duration-500"
                    :class="p.cls"
                    :style="{ width: `${p.pct}%` }"
                    :title="`${p.label}: ${mxn(p.amount)}`"
                />
            </div>
            <ul
                class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
            >
                <li
                    v-for="p in parts"
                    :key="p.key"
                    class="flex items-center gap-1.5"
                    :class="p.pct > 0 ? '' : 'opacity-45'"
                >
                    <span class="size-2.5 rounded-sm" :class="p.cls" />
                    {{ p.label }}
                    <b class="tabular-nums">{{ mxn(p.amount) }}</b>
                </li>
                <li class="ml-auto text-muted-foreground">
                    El capital queda fuera del Estado de Resultados
                </li>
            </ul>
        </div>
    </section>
</template>
