<script setup lang="ts">
/**
 * FinancialAllocationBars — prorrateo gerencial del costo financiero por sucursal.
 *
 * La usa FinancialExpensesPanel. Por crédito: barras horizontales con participación %
 * y costo asignado, fila «Sin asignar» en gris (motivo en popover) y un check
 * «Cuadra al centavo» cuando asignado + sin asignar = reconocido. Es analítico:
 * no cambia el Estado de Resultados.
 *
 * Props:
 * - allocation: prorrateo del periodo (FinancialExpenseService::allocation).
 */
import { CheckCircle2, CircleAlert, PieChart } from 'lucide-vue-next';
import type {
    AllocationCredit,
    FinancialAllocation,
} from './financialExpenses';
import FinInfoTip from './FinInfoTip.vue';
import { mxn, num } from './types';

defineProps<{ allocation: FinancialAllocation }>();

/** Ancho (%) de un importe contra el reconocido del crédito. */
function width(c: AllocationCredit, amount: string): number {
    const total = Math.abs(num(c.recognized.total));

    return total ? (Math.abs(num(amount)) / total) * 100 : 0;
}
</script>

<template>
    <section
        aria-label="Distribución por sucursal"
        class="space-y-3 rounded-2xl border bg-card p-4 shadow-soft-xs"
    >
        <p class="flex items-center gap-2 text-sm font-extrabold">
            <PieChart class="size-4 text-cyan-600 dark:text-cyan-400" />
            Distribución por sucursal
            <FinInfoTip :text="allocation.note" label="Cómo se reparte" />
        </p>

        <div class="grid gap-3 lg:grid-cols-2">
            <div
                v-for="c in allocation.credits"
                :key="c.credit_key"
                class="space-y-2 rounded-xl border p-3"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-bold">
                        {{ c.credit_name }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ mxn(c.recognized.total) }}</span
                        >
                    </p>
                    <span
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                        :class="
                            c.check.ok
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                        "
                    >
                        <CheckCircle2 v-if="c.check.ok" class="size-3.5" />
                        <CircleAlert v-else class="size-3.5" />
                        {{ c.check.ok ? 'Cuadra al centavo' : 'No cuadra' }}
                    </span>
                </div>

                <ul class="space-y-1.5">
                    <li
                        v-for="b in c.branches"
                        :key="b.branch"
                        class="text-[11px]"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="truncate font-medium">{{
                                b.branch
                            }}</span>
                            <span class="shrink-0 tabular-nums">
                                <b>{{ (num(b.share) * 100).toFixed(1) }}%</b>
                                · {{ mxn(b.assigned) }}
                            </span>
                        </div>
                        <div class="mt-0.5 h-2 rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-cyan-500 transition-[width] duration-500 dark:bg-cyan-400"
                                :style="{ width: `${width(c, b.assigned)}%` }"
                            />
                        </div>
                    </li>
                    <li
                        v-if="num(c.unassigned.amount) !== 0"
                        class="text-[11px]"
                    >
                        <div
                            class="flex items-center justify-between gap-2 text-muted-foreground"
                        >
                            <span class="flex items-center gap-1 font-medium">
                                Sin asignar
                                <FinInfoTip
                                    v-if="c.unassigned.reason"
                                    :text="c.unassigned.reason"
                                    label="Por qué queda sin asignar"
                                />
                            </span>
                            <span class="tabular-nums">{{
                                mxn(c.unassigned.amount)
                            }}</span>
                        </div>
                        <div class="mt-0.5 h-2 rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-[repeating-linear-gradient(135deg,#94a3b8_0_5px,#cbd5e1_5px_10px)] dark:bg-[repeating-linear-gradient(135deg,#475569_0_5px,#64748b_5px_10px)]"
                                :style="{
                                    width: `${width(c, c.unassigned.amount)}%`,
                                }"
                            />
                        </div>
                    </li>
                    <li
                        v-if="
                            !c.branches.length && num(c.unassigned.amount) === 0
                        "
                        class="text-[11px] text-muted-foreground"
                    >
                        Sin costo financiero que repartir.
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
