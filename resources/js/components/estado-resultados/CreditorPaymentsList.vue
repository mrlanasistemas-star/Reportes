<script setup lang="ts">
/**
 * CreditorPaymentsList — pagos previstos del crédito contra los cargos al acreedor.
 *
 * La usa CreditStatusCard (dentro de un colapsable). Tabla compacta con ícono de
 * estado en escritorio y tarjetas en móvil; debajo, pagos no previstos y reversos.
 *
 * Props:
 * - payments: pagos al acreedor del periodo (CreditPeriod.creditor_payments).
 * - showCommission: muestra la columna de comisión (LEROM).
 */
import {
    CheckCircle2,
    CircleAlert,
    CircleDashed,
    Clock,
    FastForward,
    HelpCircle,
    Undo2,
} from 'lucide-vue-next';
import type { Component } from 'vue';
import { dmy } from './financialExpenses';
import type { CreditorPaymentRow, CreditorPayments } from './financialExpenses';
import { mxn } from './types';

defineProps<{ payments: CreditorPayments; showCommission: boolean }>();

/** Ícono, color y texto corto de cada estado del pago. */
function meta(r: CreditorPaymentRow): {
    icon: Component;
    cls: string;
    label: string;
} {
    if (r.ambiguous) {
        return {
            icon: HelpCircle,
            cls: 'text-violet-600 dark:text-violet-400',
            label: 'Varios cargos posibles: se revisa a mano',
        };
    }

    switch (r.status) {
        case 'conciliado':
            return {
                icon: CheckCircle2,
                cls: 'text-emerald-600 dark:text-emerald-400',
                label:
                    r.stage === 'confirmado'
                        ? 'Conciliado y confirmado'
                        : 'Conciliado con el banco',
            };
        case 'parcial':
            return {
                icon: CircleAlert,
                cls: 'text-amber-600 dark:text-amber-400',
                label: 'Pago parcial',
            };
        case 'atrasado':
            return {
                icon: Clock,
                cls: 'text-rose-600 dark:text-rose-400',
                label: `Pagado con atraso${r.days ? ` (${r.days} d)` : ''}`,
            };
        case 'adelantado':
            return {
                icon: FastForward,
                cls: 'text-sky-600 dark:text-sky-400',
                label: 'Pagado por adelantado',
            };
        default:
            return r.due
                ? {
                      icon: CircleAlert,
                      cls: 'text-amber-600 dark:text-amber-400',
                      label: 'No localizado en el banco',
                  }
                : {
                      icon: CircleDashed,
                      cls: 'text-muted-foreground',
                      label: 'Previsto (aún no vence)',
                  };
    }
}

/** La diferencia no es cero. */
const off = (v: string) => Number(v) !== 0;
</script>

<template>
    <div class="space-y-3">
        <p
            v-if="!payments.rows.length"
            class="flex items-center gap-2 rounded-xl bg-muted/40 p-3 text-xs text-muted-foreground"
        >
            <CircleDashed class="size-4" /> Sin pagos programados en el periodo.
        </p>

        <template v-else>
            <!-- Escritorio: tabla compacta -->
            <div class="hidden overflow-hidden rounded-xl border sm:block">
                <table class="w-full text-xs">
                    <thead class="bg-muted/60 text-left text-[11px]">
                        <tr>
                            <th class="w-8 px-2 py-1.5">
                                <span class="sr-only">Estado</span>
                            </th>
                            <th class="px-2 py-1.5">No.</th>
                            <th class="px-2 py-1.5">Fecha</th>
                            <th class="px-2 py-1.5 text-right">Esperado</th>
                            <th class="px-2 py-1.5 text-right">Banco</th>
                            <th class="px-2 py-1.5 text-right">Interés</th>
                            <th
                                v-if="showCommission"
                                class="px-2 py-1.5 text-right"
                            >
                                Comisión
                            </th>
                            <th class="px-2 py-1.5 text-right">IVA</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="r in payments.rows"
                            :key="r.row_id"
                            class="border-t"
                        >
                            <td class="px-2 py-1.5">
                                <component
                                    :is="meta(r).icon"
                                    class="size-4"
                                    :class="meta(r).cls"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">{{ meta(r).label }}</span>
                            </td>
                            <td
                                class="px-2 py-1.5 tabular-nums"
                                :title="meta(r).label"
                            >
                                {{ r.number ?? '—' }}
                            </td>
                            <td class="px-2 py-1.5 tabular-nums">
                                {{ dmy(r.scheduled_date) }}
                                <span
                                    v-if="
                                        r.bank_date &&
                                        r.bank_date !== r.scheduled_date
                                    "
                                    class="text-muted-foreground"
                                    >→ {{ dmy(r.bank_date) }}</span
                                >
                            </td>
                            <td class="px-2 py-1.5 text-right tabular-nums">
                                {{ mxn(r.expected_payment) }}
                            </td>
                            <td
                                class="px-2 py-1.5 text-right tabular-nums"
                                :class="
                                    off(r.detected_payment)
                                        ? ''
                                        : 'text-muted-foreground/60'
                                "
                            >
                                {{
                                    off(r.detected_payment)
                                        ? mxn(r.detected_payment)
                                        : '—'
                                }}
                            </td>
                            <td
                                class="px-2 py-1.5 text-right font-semibold text-rose-700 tabular-nums dark:text-rose-400"
                            >
                                {{ mxn(r.interest) }}
                            </td>
                            <td
                                v-if="showCommission"
                                class="px-2 py-1.5 text-right text-rose-600 tabular-nums dark:text-rose-300"
                            >
                                {{ mxn(r.commission) }}
                            </td>
                            <td
                                class="px-2 py-1.5 text-right text-muted-foreground tabular-nums"
                            >
                                {{ mxn(r.vat) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Móvil: tarjetas -->
            <ul class="space-y-1.5 sm:hidden">
                <li
                    v-for="r in payments.rows"
                    :key="r.row_id"
                    class="flex items-center gap-2.5 rounded-xl border p-2.5 text-xs"
                >
                    <component
                        :is="meta(r).icon"
                        class="size-5 shrink-0"
                        :class="meta(r).cls"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold tabular-nums">
                            #{{ r.number ?? '—' }} · {{ dmy(r.scheduled_date) }}
                        </p>
                        <p class="truncate text-[11px] text-muted-foreground">
                            {{ meta(r).label }}
                        </p>
                    </div>
                    <div class="text-right tabular-nums">
                        <p class="font-bold">{{ mxn(r.expected_payment) }}</p>
                        <p class="text-[11px] text-rose-700 dark:text-rose-400">
                            {{ mxn(Number(r.interest) + Number(r.commission)) }}
                        </p>
                    </div>
                </li>
            </ul>
        </template>

        <div v-if="payments.unscheduled.length" class="space-y-1">
            <p class="text-[11px] font-bold">Cargos no previstos</p>
            <ul class="flex flex-wrap gap-1.5">
                <li
                    v-for="u in payments.unscheduled"
                    :key="u.fingerprint"
                    class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-900 tabular-nums dark:bg-amber-950/50 dark:text-amber-200"
                    :title="u.concept ?? ''"
                >
                    {{ dmy(u.date) }} · {{ mxn(u.amount) }}
                </li>
            </ul>
        </div>
        <div v-if="payments.reversals.length" class="space-y-1">
            <p class="flex items-center gap-1 text-[11px] font-bold">
                <Undo2 class="size-3.5" /> Reversos del acreedor
            </p>
            <ul class="flex flex-wrap gap-1.5">
                <li
                    v-for="u in payments.reversals"
                    :key="u.fingerprint"
                    class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] text-sky-900 tabular-nums dark:bg-sky-950/50 dark:text-sky-200"
                    :title="u.concept ?? ''"
                >
                    {{ dmy(u.date) }} · {{ mxn(u.amount) }}
                </li>
            </ul>
        </div>
    </div>
</template>
