<script setup lang="ts">
/**
 * WithdrawalItemsList — lista desplegable de los movimientos que cuentan como
 * retiro de Dirección General o de socios (sección 10 del estado de resultados,
 * sólo consolidado).
 *
 * La usa StatementTable debajo de la sección «Retiros de Dirección General y
 * socios». Por cada movimiento muestra fecha, concepto, cuenta, quién se lo
 * llevó e importe; si el usuario no tiene permiso para ver el detalle de DG
 * (restricted) sólo se avisa, sin listar nada.
 *
 * Props:
 * - withdrawals: bloque `withdrawals` del dataset.
 */
import { ChevronDown, Lock, UserRound } from 'lucide-vue-next';
import { ref } from 'vue';
import { isNegative, mxn } from './types';
import type { Withdrawals } from './types';

defineProps<{ withdrawals: Withdrawals }>();

/** Lista abierta o cerrada (cerrada por defecto). */
const open = ref(false);
</script>

<template>
    <div class="px-4 pb-2 pl-12">
        <p
            v-if="withdrawals.restricted"
            class="inline-flex items-center gap-1.5 text-xs text-muted-foreground italic"
        >
            <Lock class="size-3.5" /> Detalle de movimientos restringido: sólo
            se ve el total.
        </p>
        <template v-else-if="withdrawals.items.length">
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 text-xs font-semibold text-amber-800 transition hover:bg-amber-50 focus-visible:ring-3 focus-visible:ring-amber-500/30 focus-visible:outline-none dark:text-amber-300 dark:hover:bg-amber-950/40"
                :aria-expanded="open"
                @click="open = !open"
            >
                <ChevronDown
                    class="size-3.5 transition-transform"
                    :class="open ? '' : '-rotate-90'"
                />
                {{ open ? 'Ocultar' : 'Ver' }} los
                {{ withdrawals.items.length }} movimiento(s) de retiro
            </button>
            <ul
                v-show="open"
                class="mt-1.5 max-h-80 divide-y overflow-y-auto rounded-xl border bg-background/60 text-xs"
            >
                <li
                    v-for="it in withdrawals.items"
                    :key="it.fingerprint"
                    class="grid grid-cols-[minmax(0,1fr)_auto] gap-x-3 gap-y-0.5 px-3 py-2 sm:grid-cols-[5.5rem_minmax(0,1fr)_auto]"
                >
                    <span
                        class="text-muted-foreground tabular-nums sm:row-span-2"
                        >{{ it.date ?? '—' }}</span
                    >
                    <span
                        class="col-start-1 min-w-0 truncate font-semibold sm:col-start-2"
                        :title="it.concept ?? it.group"
                        >{{ it.concept ?? it.group }}</span
                    >
                    <span
                        class="row-span-2 self-center text-right font-bold tabular-nums sm:col-start-3 sm:row-start-1"
                        :class="
                            isNegative(it.amount)
                                ? 'text-emerald-700 dark:text-emerald-400'
                                : ''
                        "
                        >{{ mxn(it.amount) }}</span
                    >
                    <span
                        class="col-start-1 flex min-w-0 flex-wrap items-center gap-x-2 text-muted-foreground sm:col-start-2"
                    >
                        <span class="truncate">{{
                            it.account_label ??
                            (it.source === 'erp' ? 'ERP' : 'Banco')
                        }}</span>
                        <span
                            class="inline-flex min-w-0 items-center gap-1 truncate"
                            ><UserRound class="size-3 shrink-0" />
                            {{ it.who }}</span
                        >
                        <span
                            v-if="it.manual"
                            class="rounded-full border px-1.5 text-[10px] font-semibold"
                            :title="it.reason ?? undefined"
                            >Clasificado a mano</span
                        >
                    </span>
                </li>
            </ul>
        </template>
    </div>
</template>
