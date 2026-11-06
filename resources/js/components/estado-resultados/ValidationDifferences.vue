<script setup lang="ts">
import { ArrowUpRight, CheckCircle2, Lock } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { mxn, num } from './types';
import type { DiffGroup, DiffItem, Validation } from './types';

const props = defineProps<{ differences: Validation['differences'] }>();
const emit = defineEmits<{ open: [fingerprint: string] }>();

const TABS: Array<{
    key: keyof Validation['differences'];
    label: string;
    explain: string;
}> = [
    {
        key: 'by_amount',
        label: 'Por importe',
        explain:
            'El folio existe en el ERP, pero el banco pagó un importe distinto.',
    },
    {
        key: 'by_date',
        label: 'Por fecha',
        explain:
            'Mismo folio e importe, pero la fecha cae fuera de la tolerancia.',
    },
    {
        key: 'by_folio',
        label: 'Por folio',
        explain:
            'El folio no está en el ERP, es de otro periodo, ya se usó o es ambiguo.',
    },
];

const tab = ref<keyof Validation['differences']>(
    TABS.find((t) => props.differences[t.key].count)?.key ?? 'by_amount',
);
const group = computed<DiffGroup>(() => props.differences[tab.value]);
const limit = ref(50);
const rows = computed<DiffItem[]>(() =>
    group.value.items.slice(0, limit.value),
);
</script>

<template>
    <section class="rounded-2xl border bg-card shadow-soft-xs">
        <header
            class="flex flex-wrap items-end justify-between gap-3 border-b px-5 pt-4"
        >
            <div class="pb-3">
                <h3 class="text-base font-extrabold">
                    Diferencias banco ↔ ERP
                </h3>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Cada renglón es un movimiento del banco que no concilió
                    limpio. Haz clic para abrirlo y decidir qué hacer.
                </p>
            </div>
            <div
                class="flex gap-1"
                role="tablist"
                aria-label="Tipo de diferencia"
            >
                <button
                    v-for="t in TABS"
                    :key="t.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.key"
                    class="-mb-px flex items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-semibold transition"
                    :class="
                        tab === t.key
                            ? 'border-rose-500 text-foreground'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    @click="
                        tab = t.key;
                        limit = 50;
                    "
                >
                    {{ t.label }}
                    <span
                        class="rounded-full px-1.5 text-[10px] font-bold tabular-nums"
                        :class="
                            differences[t.key].count
                                ? 'bg-rose-500 text-white'
                                : 'bg-muted text-muted-foreground'
                        "
                        >{{ differences[t.key].count }}</span
                    >
                </button>
            </div>
        </header>

        <p class="px-5 pt-3 text-xs text-muted-foreground">
            {{ TABS.find((t) => t.key === tab)?.explain }}
            <template v-if="group.count">
                Total en banco:
                <b class="text-rose-700 tabular-nums dark:text-rose-400">{{
                    mxn(group.amount)
                }}</b>
            </template>
        </p>

        <div
            v-if="!group.count"
            class="flex items-center gap-2 px-5 py-8 text-sm text-emerald-700 dark:text-emerald-400"
        >
            <CheckCircle2 class="size-5" /> Sin diferencias de este tipo.
        </div>
        <div v-else class="max-h-[60vh] overflow-auto p-2">
            <table
                class="w-full min-w-[920px] border-separate border-spacing-0 text-xs"
            >
                <thead
                    class="sticky top-0 z-10 bg-card text-left text-[11px] text-muted-foreground"
                >
                    <tr
                        class="[&>th]:border-b [&>th]:px-2.5 [&>th]:py-2 [&>th]:font-semibold"
                    >
                        <th>Fecha</th>
                        <th>Folio</th>
                        <th>Concepto</th>
                        <th class="text-right">Banco</th>
                        <th class="text-right">ERP</th>
                        <th class="text-right">Diferencia</th>
                        <th>Qué pasó</th>
                        <th><span class="sr-only">Abrir</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(r, i) in rows"
                        :key="r.fingerprint ?? i"
                        :tabindex="r.fingerprint ? 0 : -1"
                        class="group [&>td]:border-b [&>td]:border-border/60 [&>td]:px-2.5 [&>td]:py-2"
                        :class="
                            r.fingerprint
                                ? 'cursor-pointer transition-colors hover:bg-rose-50/60 focus-visible:bg-rose-50 focus-visible:outline-none dark:hover:bg-rose-950/20'
                                : ''
                        "
                        @click="r.fingerprint && emit('open', r.fingerprint)"
                        @keydown.enter="
                            r.fingerprint && emit('open', r.fingerprint)
                        "
                    >
                        <td class="whitespace-nowrap tabular-nums">
                            {{ r.date }}
                        </td>
                        <td
                            class="font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{ r.folio ?? '—' }}
                        </td>
                        <td class="max-w-[280px]">
                            <span class="flex items-center gap-1 truncate">
                                <Lock
                                    v-if="r.redacted"
                                    class="size-3 shrink-0"
                                />
                                {{ r.concept ?? '—' }}
                            </span>
                            <span class="text-[10.5px] text-muted-foreground">{{
                                r.account_slot
                            }}</span>
                        </td>
                        <td
                            class="text-right font-semibold whitespace-nowrap tabular-nums"
                        >
                            {{ mxn(r.bank_amount) }}
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            {{
                                r.erp_amounts.length
                                    ? r.erp_amounts
                                          .map((a) => mxn(a))
                                          .join(' + ')
                                    : '—'
                            }}
                        </td>
                        <td
                            class="text-right font-bold whitespace-nowrap tabular-nums"
                            :class="
                                r.difference && num(r.difference)
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ r.difference ? mxn(r.difference) : '—' }}
                            <span
                                v-if="r.delta_days !== null"
                                class="block text-[10.5px] font-semibold"
                                >{{ r.delta_days }} día(s)</span
                            >
                        </td>
                        <td class="max-w-[300px]">
                            <span
                                class="inline-block rounded-full border border-rose-200 bg-rose-50 px-2 py-0.5 text-[10.5px] font-semibold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300"
                                >{{ r.status_label }}</span
                            >
                            <span
                                v-if="r.message"
                                class="mt-0.5 line-clamp-2 block text-[10.5px] text-muted-foreground"
                                >{{ r.message }}</span
                            >
                        </td>
                        <td class="text-right">
                            <ArrowUpRight
                                v-if="r.fingerprint"
                                class="inline size-4 text-muted-foreground transition group-hover:text-rose-600"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
            <button
                v-if="group.items.length > limit"
                type="button"
                class="mt-2 w-full rounded-xl border border-dashed py-2 text-xs font-semibold text-muted-foreground hover:text-foreground"
                @click="limit += 100"
            >
                Mostrar más ({{ group.items.length - limit }} restantes)
            </button>
        </div>
    </section>
</template>
