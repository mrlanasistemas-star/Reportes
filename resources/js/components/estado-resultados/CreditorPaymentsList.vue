<script setup lang="ts">
/**
 * CreditorPaymentsList — pagos de la tabla del periodo y lo aplicado a cada uno.
 *
 * La usa CreditStatusCard (dentro de un colapsable). Cada pago muestra si quedó
 * cubierto con lo aplicado (aportaciones de sucursales / pagos al acreedor, en orden
 * FIFO), el importe aplicado y el faltante, con una barra de avance. El cargo bancario
 * conciliado con el renglón (si lo hay) queda como dato secundario. Debajo: cargos no
 * previstos al acreedor y reversos.
 *
 * Props:
 * - payments: pagos al acreedor del periodo (CreditPeriod.creditor_payments).
 * - basis: de dónde salió lo aplicado (CreditApplication.basis).
 */
import {
    CheckCircle2,
    CircleAlert,
    CircleDashed,
    PieChart,
    Undo2,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { dmy } from './financialExpenses';
import type {
    CreditApplication,
    CreditorPaymentRow,
    CreditorPayments,
} from './financialExpenses';
import { mxn, num } from './types';

const props = defineProps<{
    payments: CreditorPayments;
    basis?: CreditApplication['basis'];
}>();

/** Ícono, color y texto corto de la cobertura del pago. */
function meta(r: CreditorPaymentRow): {
    icon: Component;
    cls: string;
    bar: string;
    label: string;
} {
    switch (r.coverage) {
        case 'cubierto':
            return {
                icon: CheckCircle2,
                cls: 'text-emerald-600 dark:text-emerald-400',
                bar: 'bg-emerald-500',
                label:
                    props.basis === 'aportaciones'
                        ? 'Cubierto con aportaciones'
                        : 'Cubierto',
            };
        case 'parcial':
            return {
                icon: PieChart,
                cls: 'text-amber-600 dark:text-amber-400',
                bar: 'bg-amber-500',
                label: 'Parcial',
            };
        default:
            return r.due
                ? {
                      icon: CircleAlert,
                      cls: 'text-rose-600 dark:text-rose-400',
                      bar: 'bg-rose-500',
                      label: 'Sin cubrir',
                  }
                : {
                      icon: CircleDashed,
                      cls: 'text-muted-foreground',
                      bar: 'bg-muted-foreground',
                      label: 'Aún no vence',
                  };
    }
}

/** % aplicado del pago (barra). */
const pct = (r: CreditorPaymentRow) => {
    const exp = num(r.expected_payment);

    return exp > 0 ? Math.min(100, (num(r.applied) / exp) * 100) : 0;
};
</script>

<template>
    <div class="space-y-3">
        <p
            v-if="!payments.rows.length"
            class="flex items-center gap-2 rounded-xl bg-muted/40 p-3 text-sm text-muted-foreground"
        >
            <CircleDashed class="size-4" /> Sin pagos programados en el periodo.
        </p>

        <ul v-else class="space-y-1.5">
            <li
                v-for="r in payments.rows"
                :key="r.row_id"
                class="grid grid-cols-[auto_1fr_auto] items-center gap-x-3 gap-y-1 rounded-xl border px-3 py-2 text-sm"
            >
                <component
                    :is="meta(r).icon"
                    class="size-5 shrink-0"
                    :class="meta(r).cls"
                    aria-hidden="true"
                />
                <div class="min-w-0">
                    <p class="font-semibold tabular-nums">
                        Pago {{ r.number ?? '—' }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ dmy(r.scheduled_date) }}</span
                        >
                    </p>
                    <p class="text-xs" :class="meta(r).cls">
                        {{ meta(r).label }}
                        <span
                            v-if="r.status === 'conciliado'"
                            class="text-muted-foreground"
                            :title="`Cargo al acreedor ${dmy(r.bank_date)}`"
                            >· banco {{ mxn(r.detected_payment) }}</span
                        >
                    </p>
                </div>
                <div class="text-right tabular-nums">
                    <p class="font-bold">{{ mxn(r.applied ?? '0') }}</p>
                    <p class="text-xs text-muted-foreground">
                        de {{ mxn(r.expected_payment) }}
                    </p>
                </div>
                <div
                    class="col-span-3 flex items-center gap-2 text-xs tabular-nums"
                >
                    <div
                        class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                        role="img"
                        :aria-label="`Aplicado ${pct(r).toFixed(0)}%`"
                    >
                        <span
                            class="block h-full rounded-full"
                            :class="meta(r).bar"
                            :style="{ width: `${pct(r)}%` }"
                        />
                    </div>
                    <span
                        v-if="num(r.shortfall) > 0"
                        class="font-semibold text-rose-700 dark:text-rose-400"
                        >faltan {{ mxn(r.shortfall) }}</span
                    >
                </div>
            </li>
        </ul>

        <div v-if="payments.unscheduled.length" class="space-y-1">
            <p class="text-xs font-bold">Cargos no previstos al acreedor</p>
            <ul class="flex flex-wrap gap-1.5">
                <li
                    v-for="u in payments.unscheduled"
                    :key="u.fingerprint"
                    class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs text-amber-900 tabular-nums dark:bg-amber-950/50 dark:text-amber-200"
                    :title="u.concept ?? ''"
                >
                    {{ dmy(u.date) }} · {{ mxn(u.amount) }}
                </li>
            </ul>
        </div>
        <div v-if="payments.reversals.length" class="space-y-1">
            <p class="flex items-center gap-1 text-xs font-bold">
                <Undo2 class="size-3.5" /> Reversos del acreedor
            </p>
            <ul class="flex flex-wrap gap-1.5">
                <li
                    v-for="u in payments.reversals"
                    :key="u.fingerprint"
                    class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs text-sky-900 tabular-nums dark:bg-sky-950/50 dark:text-sky-200"
                    :title="u.concept ?? ''"
                >
                    {{ dmy(u.date) }} · {{ mxn(u.amount) }}
                </li>
            </ul>
        </div>
    </div>
</template>
