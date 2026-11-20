<script setup lang="ts">
import { dmy } from './financialExpenses';
import type { CreditPeriod } from './financialExpenses';
import { mxn } from './types';

defineProps<{ credit: CreditPeriod }>();
</script>

<template>
    <div class="overflow-x-auto rounded-2xl border bg-card">
        <p v-if="!credit.rows.length" class="p-4 text-xs text-muted-foreground">
            {{
                credit.covered
                    ? 'La tabla no tiene pagos con fecha dentro de este periodo: el interés del periodo es $0.00.'
                    : 'No hay renglones de la tabla en este periodo.'
            }}
        </p>
        <table v-else class="w-full min-w-[640px] text-xs">
            <caption class="sr-only">
                Pagos de
                {{
                    credit.credit.name
                }}
                con fecha en el periodo
            </caption>
            <thead class="bg-muted/60 text-left">
                <tr>
                    <th class="px-3 py-1.5">No.</th>
                    <th class="px-3 py-1.5">Fecha de pago</th>
                    <th class="px-3 py-1.5 text-right">Pago total</th>
                    <th class="px-3 py-1.5 text-right">
                        Capital <span class="font-normal">(no entra)</span>
                    </th>
                    <th class="px-3 py-1.5 text-right">
                        Interés <span class="font-normal">(EBIT → EBT)</span>
                    </th>
                    <th class="px-3 py-1.5 text-right">IVA interés</th>
                    <th class="px-3 py-1.5 text-right">Saldo</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="r in credit.rows" :key="r.id" class="border-t">
                    <td class="px-3 py-1.5 tabular-nums">
                        {{ r.number ?? '—' }}
                    </td>
                    <td class="px-3 py-1.5 tabular-nums">{{ dmy(r.date) }}</td>
                    <td class="px-3 py-1.5 text-right tabular-nums">
                        {{ mxn(r.payment) }}
                    </td>
                    <td
                        class="px-3 py-1.5 text-right text-muted-foreground tabular-nums line-through decoration-muted-foreground/40"
                    >
                        {{ mxn(r.principal) }}
                    </td>
                    <td
                        class="px-3 py-1.5 text-right font-bold text-rose-700 tabular-nums dark:text-rose-400"
                    >
                        {{ mxn(r.interest) }}
                    </td>
                    <td class="px-3 py-1.5 text-right tabular-nums">
                        {{
                            r.interest_vat === null ? '—' : mxn(r.interest_vat)
                        }}
                    </td>
                    <td class="px-3 py-1.5 text-right tabular-nums">
                        {{ r.balance === null ? '—' : mxn(r.balance) }}
                    </td>
                </tr>
            </tbody>
            <tfoot class="border-t-2 bg-muted/30 font-extrabold">
                <tr>
                    <td class="px-3 py-1.5" colspan="2">
                        {{ credit.totals.count }} pago(s) en el periodo
                    </td>
                    <td class="px-3 py-1.5 text-right tabular-nums">
                        {{ mxn(credit.totals.payment) }}
                    </td>
                    <td class="px-3 py-1.5 text-right tabular-nums">
                        {{ mxn(credit.totals.principal) }}
                    </td>
                    <td
                        class="px-3 py-1.5 text-right text-rose-700 tabular-nums dark:text-rose-400"
                    >
                        {{ mxn(credit.totals.interest) }}
                    </td>
                    <td class="px-3 py-1.5 text-right tabular-nums">
                        {{ mxn(credit.totals.interest_vat) }}
                    </td>
                    <td class="px-3 py-1.5" />
                </tr>
            </tfoot>
        </table>
    </div>
</template>
