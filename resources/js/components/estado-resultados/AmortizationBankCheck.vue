<script setup lang="ts">
import { CheckCircle2, CircleAlert, Landmark } from 'lucide-vue-next';
import { computed } from 'vue';
import { dmy } from './financialExpenses';
import type { CreditPeriod } from './financialExpenses';
import { mxn } from './types';

const props = defineProps<{ credit: CreditPeriod }>();

const bank = computed(() => props.credit.bank);
const ok = computed(
    () =>
        bank.value.totals.expected_count === bank.value.totals.matched_count &&
        bank.value.unmatched_bank.length === 0,
);
</script>

<template>
    <div class="space-y-2 rounded-2xl border bg-card p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="flex items-center gap-2 text-sm font-bold">
                <Landmark class="size-4 text-sky-600 dark:text-sky-400" />
                Cruce contra el banco
            </p>
            <span
                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold"
                :class="
                    ok
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                        : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300'
                "
            >
                <CheckCircle2 v-if="ok" class="size-3.5" />
                <CircleAlert v-else class="size-3.5" />
                {{ bank.totals.matched_count }} de
                {{ bank.totals.expected_count }} pagos encontrados
            </span>
        </div>
        <p class="text-[11px] text-muted-foreground">
            Sólo es evidencia: el pago bancario completo sigue fuera del Estado
            de Resultados (capital de deuda). Se busca el cargo «{{
                bank.pattern ?? '—'
            }}» con ±{{ bank.tolerance_days }} día(s) y ±{{
                mxn(bank.tolerance_amount)
            }}.
        </p>
        <dl class="grid grid-cols-3 gap-2 text-xs">
            <div class="rounded-xl bg-muted/40 p-2">
                <dt class="text-muted-foreground">Esperado (tabla)</dt>
                <dd class="font-bold tabular-nums">
                    {{ mxn(bank.totals.expected) }}
                </dd>
            </div>
            <div class="rounded-xl bg-muted/40 p-2">
                <dt class="text-muted-foreground">
                    Encontrado ({{ bank.totals.found_count }})
                </dt>
                <dd class="font-bold tabular-nums">
                    {{ mxn(bank.totals.found) }}
                </dd>
            </div>
            <div class="rounded-xl bg-muted/40 p-2">
                <dt class="text-muted-foreground">Diferencia</dt>
                <dd
                    class="font-bold tabular-nums"
                    :class="
                        Number(bank.totals.difference) === 0
                            ? ''
                            : 'text-amber-700 dark:text-amber-400'
                    "
                >
                    {{ mxn(bank.totals.difference) }}
                </dd>
            </div>
        </dl>
        <ul v-if="bank.expected.length" class="space-y-1 text-[11px]">
            <li
                v-for="e in bank.expected"
                :key="e.row_id"
                class="flex flex-wrap items-center gap-x-2 rounded-lg px-2 py-1"
                :class="
                    e.status === 'encontrado'
                        ? 'bg-emerald-50 dark:bg-emerald-950/30'
                        : 'bg-amber-50 dark:bg-amber-950/30'
                "
            >
                <span class="font-semibold tabular-nums"
                    >Pago {{ e.number ?? '' }} · {{ dmy(e.date) }} ·
                    {{ mxn(e.payment) }}</span
                >
                <span v-if="e.match" class="text-muted-foreground"
                    >→ banco {{ dmy(e.match.date) }}
                    {{ mxn(e.match.amount) }} ({{
                        e.match.days
                    }}
                    día(s)<template v-if="Number(e.match.difference) !== 0"
                        >, dif. {{ mxn(e.match.difference) }}</template
                    >)</span
                >
                <span
                    v-else
                    class="font-semibold text-amber-800 dark:text-amber-300"
                    >No se encontró en el banco del periodo</span
                >
            </li>
        </ul>
        <div v-if="bank.unmatched_bank.length" class="space-y-1">
            <p class="text-[11px] font-bold">
                Pagos en el banco que no corresponden a la tabla
            </p>
            <ul class="space-y-1 text-[11px]">
                <li
                    v-for="u in bank.unmatched_bank"
                    :key="u.fingerprint"
                    class="rounded-lg bg-amber-50 px-2 py-1 dark:bg-amber-950/30"
                >
                    {{ dmy(u.date) }} · {{ mxn(u.amount) }} · {{ u.concept }}
                </li>
            </ul>
        </div>
    </div>
</template>
