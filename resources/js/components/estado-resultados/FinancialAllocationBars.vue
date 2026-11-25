<script setup lang="ts">
/**
 * FinancialAllocationBars — reparto automático del servicio de deuda por sucursal.
 *
 * La usa FinancialExpensesPanel. Por crédito: a cada sucursal le toca lo que se
 * debía pagar en el periodo × su participación en las aportaciones (al centavo).
 * Barra horizontal por sucursal: «le toca» como meta (marca) y «aportó» como
 * relleno (verde si cubrió, ámbar si cubrió al menos la mitad, rojo si menos), con
 * la diferencia (debe / a favor). Si hay interés (p. ej. BBVA), su parte por
 * sucursal. Aportaciones sin sucursal (o todo, si no hubo aportaciones) van en
 * «Sin asignar». Es analítico: no cambia el Estado de Resultados.
 *
 * Props:
 * - allocation: reparto del periodo (FinancialExpenseService::allocation).
 */
import { CheckCircle2, CircleAlert, PieChart } from 'lucide-vue-next';
import { computed } from 'vue';
import type {
    AllocationCredit,
    BranchStanding,
    FinancialAllocation,
} from './financialExpenses';
import FinInfoTip from './FinInfoTip.vue';
import { mxn, num } from './types';

const props = defineProps<{ allocation: FinancialAllocation }>();

/** Renglón visual (sucursal o «Sin asignar»). */
interface BarRow {
    key: string;
    label: string;
    due: number;
    contributed: number;
    difference: number;
    interest: number;
    standing: BranchStanding | null;
    unassigned: boolean;
    reason: string | null;
}

/** Créditos con algo que mostrar y sus renglones. */
const credits = computed(() =>
    props.allocation.credits.map((c) => {
        const rows: BarRow[] = c.branches.map((b) => ({
            key: b.branch,
            label: b.branch,
            due: num(b.due),
            contributed: num(b.contributed),
            difference: num(b.difference),
            interest: num(b.interest),
            standing: b.standing,
            unassigned: false,
            reason: null,
        }));
        const u = c.unassigned;

        if (num(u.due) !== 0 || num(u.contributed) !== 0) {
            rows.push({
                key: '__sin_asignar__',
                label: 'Sin asignar',
                due: num(u.due),
                contributed: num(u.contributed),
                difference: num(u.difference),
                interest: num(u.interest),
                standing: null,
                unassigned: true,
                reason: u.reason,
            });
        }

        const max = Math.max(
            1,
            ...rows.map((r) => Math.max(r.due, r.contributed)),
        );
        const ds = c.debt_service;
        const empty =
            num(ds.expected) === 0 &&
            num(ds.contributed) === 0 &&
            num(c.recognized.total) === 0;

        return {
            c,
            rows,
            max,
            empty,
            ok: ds.ok && c.check.ok,
            withInterest: num(c.recognized.interest) !== 0,
        };
    }),
);

/** Ancho (%) contra el máximo del crédito. */
function pct(v: number, max: number): number {
    return Math.min(100, (Math.abs(v) / max) * 100);
}

/** Color del relleno «aportó» según qué tanto cubre lo que le toca. */
function fillCls(r: BarRow): string {
    if (r.unassigned) {
        return 'bg-[repeating-linear-gradient(135deg,#94a3b8_0_5px,#cbd5e1_5px_10px)] dark:bg-[repeating-linear-gradient(135deg,#475569_0_5px,#64748b_5px_10px)]';
    }

    if (r.due === 0 || r.contributed >= r.due) {
        return 'bg-emerald-500 dark:bg-emerald-400';
    }

    return r.contributed / r.due >= 0.5
        ? 'bg-amber-400 dark:bg-amber-400'
        : 'bg-rose-500 dark:bg-rose-400';
}

/** Pastilla de la diferencia. */
function diffChip(r: BarRow): { text: string; cls: string } {
    if (r.difference < 0) {
        return {
            text: `Debe ${mxn(-r.difference)}`,
            cls: r.unassigned
                ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
        };
    }

    return {
        text: r.difference > 0 ? `A favor ${mxn(r.difference)}` : 'Cubierto',
        cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    };
}

/** Título accesible del renglón. */
function aria(c: AllocationCredit, r: BarRow): string {
    return `${c.credit_name} · ${r.label}: le toca ${mxn(r.due)}, aportó ${mxn(r.contributed)}`;
}
</script>

<template>
    <section
        aria-label="Distribución por sucursal"
        class="space-y-3 rounded-2xl border bg-card p-4 shadow-soft-xs"
    >
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
            <p class="flex items-center gap-2 text-sm font-extrabold">
                <PieChart class="size-4 text-cyan-600 dark:text-cyan-400" />
                Distribución por sucursal
                <FinInfoTip :text="allocation.note" label="Cómo se reparte" />
            </p>
            <p
                class="ml-auto flex items-center gap-3 text-[11px] text-muted-foreground"
            >
                <span class="inline-flex items-center gap-1"
                    ><span class="h-2 w-4 rounded-full bg-emerald-500" />
                    Aportó</span
                >
                <span class="inline-flex items-center gap-1"
                    ><span class="h-2.5 w-0.5 rounded bg-foreground/70" /> Le
                    toca</span
                >
            </p>
        </div>

        <div class="grid gap-3 lg:grid-cols-2">
            <div
                v-for="x in credits"
                :key="x.c.credit_key"
                class="space-y-2 rounded-xl border p-3"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-bold">
                        {{ x.c.credit_name }}
                        <span
                            v-if="!x.empty"
                            class="font-normal text-muted-foreground tabular-nums"
                            >· debía pagar
                            {{ mxn(x.c.debt_service.expected) }} · aportado
                            {{ mxn(x.c.debt_service.contributed) }}</span
                        >
                    </p>
                    <span
                        v-if="!x.empty"
                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                        :class="
                            x.ok
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                        "
                    >
                        <CheckCircle2 v-if="x.ok" class="size-3.5" />
                        <CircleAlert v-else class="size-3.5" />
                        {{ x.ok ? 'Cuadra al centavo' : 'No cuadra' }}
                    </span>
                </div>

                <p v-if="x.empty" class="text-[11px] text-muted-foreground">
                    Sin pagos ni aportaciones en el periodo.
                </p>

                <ul v-else class="space-y-2">
                    <li
                        v-for="r in x.rows"
                        :key="r.key"
                        class="text-[11px]"
                        :aria-label="aria(x.c, r)"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span
                                class="flex min-w-0 items-center gap-1 truncate font-semibold"
                                :class="r.unassigned && 'text-muted-foreground'"
                            >
                                {{ r.label }}
                                <FinInfoTip
                                    v-if="r.reason"
                                    :text="r.reason"
                                    label="Por qué queda sin asignar"
                                />
                            </span>
                            <span
                                class="shrink-0 rounded-full px-1.5 py-0.5 font-semibold tabular-nums"
                                :class="diffChip(r).cls"
                                >{{ diffChip(r).text }}</span
                            >
                        </div>
                        <div class="relative mt-1 h-2.5 rounded-full bg-muted">
                            <div
                                class="absolute inset-y-0 left-0 rounded-full transition-[width] duration-500"
                                :class="fillCls(r)"
                                :style="{
                                    width: `${pct(r.contributed, x.max)}%`,
                                }"
                            />
                            <div
                                v-if="r.due > 0"
                                class="absolute -inset-y-0.5 w-0.5 rounded bg-foreground/70"
                                :style="{
                                    left: `calc(${pct(r.due, x.max)}% - 1px)`,
                                }"
                            />
                        </div>
                        <p
                            class="mt-0.5 flex flex-wrap gap-x-2 text-muted-foreground tabular-nums"
                        >
                            <span
                                >Le toca
                                <b class="text-foreground">{{
                                    mxn(r.due)
                                }}</b></span
                            >
                            <span
                                >Aportó
                                <b class="text-foreground">{{
                                    mxn(r.contributed)
                                }}</b></span
                            >
                            <span v-if="x.withInterest"
                                >Interés
                                <b class="text-rose-700 dark:text-rose-400">{{
                                    mxn(r.interest)
                                }}</b></span
                            >
                        </p>
                    </li>
                </ul>
            </div>
        </div>
    </section>
</template>
