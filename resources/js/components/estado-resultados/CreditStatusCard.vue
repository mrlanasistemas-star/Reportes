<script setup lang="ts">
/**
 * CreditStatusCard — tarjeta visual de un crédito (LEROM, BBVA, futuros) en el periodo.
 *
 * La usa FinancialExpensesPanel. Cabecera con nombre, estado, «Simulación» y cobertura;
 * anillo de pagos conciliados vs. previstos; próximo pago, pagos restantes y saldo
 * según tabla; interés y comisión reconocidos. Debajo, colapsables: pagos del periodo,
 * aportaciones, tabla de amortización y carga del archivo.
 *
 * Props:
 * - credit: crédito del periodo.
 * - accept / canUpload / uploading: carga de la tabla (AmortizationUploadCard).
 *
 * Emits:
 * - upload(file, kind), template(), goAdjustments().
 */
import {
    AlertTriangle,
    CalendarClock,
    ChevronDown,
    FileSpreadsheet,
    HandCoins,
    ListChecks,
    Table2,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { Component } from 'vue';
import AmortizationScheduleTable from './AmortizationScheduleTable.vue';
import AmortizationUploadCard from './AmortizationUploadCard.vue';
import CreditorPaymentsList from './CreditorPaymentsList.vue';
import { PERIODICITY_LABEL, dmy } from './financialExpenses';
import type { CreditPeriod, DocumentKind } from './financialExpenses';
import FinInfoTip from './FinInfoTip.vue';
import { mxn, num } from './types';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';

const props = defineProps<{
    credit: CreditPeriod;
    accept: string;
    canUpload: boolean;
    uploading: boolean;
}>();

const emit = defineEmits<{
    upload: [file: File, kind: DocumentKind];
    template: [];
    goAdjustments: [];
}>();

/** Secciones colapsables abiertas. */
const open = ref<Record<string, boolean>>({});

/** Etiqueta y colores del estado derivado del crédito. */
const STATUS: Record<string, { label: string; cls: string }> = {
    vigente: {
        label: 'Vigente',
        cls: 'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-900',
    },
    sin_pagos_programados: {
        label: 'Sin pagos programados',
        cls: 'bg-slate-100 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
    },
    pago_no_localizado: {
        label: 'Pago no localizado',
        cls: 'bg-amber-100 text-amber-900 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900',
    },
    posible_liquidacion: {
        label: 'Posible liquidación',
        cls: 'bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-950 dark:text-sky-300 dark:border-sky-900',
    },
};

const status = computed(() => props.credit.status ?? null);
const statusMeta = computed(() =>
    status.value ? (STATUS[status.value.status] ?? null) : null,
);
const isSimulation = computed(
    () =>
        (status.value?.document_kind ?? props.credit.upload?.document_kind) ===
        'simulacion',
);

/** Chip de cobertura (sólo cuando no es completa). */
const coverageChip = computed(() => {
    const c = props.credit.coverage;

    if (!c || c.status === 'completo') {
        return null;
    }

    return (
        {
            parcial: {
                label: 'Cobertura parcial',
                cls: 'bg-sky-50 text-sky-800 dark:bg-sky-950/50 dark:text-sky-300',
            },
            fuera_de_calendario: {
                label: 'Fuera de calendario',
                cls: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
            },
            sin_tabla: {
                label: 'Sin tabla',
                cls: 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-300',
            },
        }[c.status] ?? null
    );
});

/** Pagos del periodo: conciliados vs. previstos. */
const totals = computed(() => props.credit.creditor_payments?.totals ?? null);
const ring = computed(() => {
    const t = totals.value;
    const expected = t?.expected_count ?? 0;
    const done = t?.reconciled_count ?? 0;
    const pct = expected ? done / expected : 0;
    const r = 26;
    const len = 2 * Math.PI * r;

    return { expected, done, pct, r, len, dash: len * pct };
});

/** Importes reconocidos (la captura manual gana). */
const recognized = computed(
    () =>
        props.credit.recognized ?? {
            interest: props.credit.totals.interest,
            commission: props.credit.totals.commission ?? '0.00',
            total: props.credit.totals.interest,
            commission_informative: null,
        },
);
const hasCommission = computed(
    () =>
        !!props.credit.credit.commission_concept ||
        num(recognized.value.commission) !== 0,
);

/** Diferencia de la captura manual contra la tabla (interés). */
const manualDiff = computed(() => {
    const a = props.credit.adjustment;

    return a?.origin === 'manual' && a.difference && num(a.difference) !== 0
        ? a.difference
        : null;
});

const contributions = computed(() => props.credit.contributions ?? null);

/** Secciones colapsables de detalle. */
const sections = computed(() => {
    const list: Array<{
        key: string;
        label: string;
        icon: Component;
        badge?: string;
    }> = [];

    if (props.credit.creditor_payments) {
        list.push({
            key: 'pagos',
            label: 'Pagos del periodo',
            icon: ListChecks,
            badge: `${ring.value.done}/${ring.value.expected}`,
        });
    }

    if (contributions.value?.count) {
        list.push({
            key: 'aportaciones',
            label: 'Aportaciones',
            icon: HandCoins,
            badge: String(contributions.value.count),
        });
    }

    if (props.credit.has_table) {
        list.push({
            key: 'tabla',
            label: 'Tabla de amortización',
            icon: Table2,
            badge: String(props.credit.rows.length),
        });
    }

    list.push({ key: 'archivo', label: 'Archivo', icon: FileSpreadsheet });

    return list;
});
</script>

<template>
    <article
        class="space-y-3 rounded-2xl border bg-card p-4 shadow-soft-xs"
        :aria-label="credit.credit.name"
    >
        <!-- Cabecera -->
        <header class="flex flex-wrap items-center gap-2">
            <h3 class="text-base font-extrabold">{{ credit.credit.name }}</h3>
            <span
                v-if="statusMeta"
                class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold"
                :class="statusMeta.cls"
                :title="status?.reason"
                >{{ statusMeta.label }}</span
            >
            <span
                v-if="isSimulation"
                class="rounded-full border border-dashed border-violet-300 bg-violet-50 px-2 py-0.5 text-[11px] font-semibold text-violet-800 dark:border-violet-800 dark:bg-violet-950/40 dark:text-violet-300"
                title="Documento no vinculante del acreedor"
                >Simulación</span
            >
            <FinInfoTip
                v-if="coverageChip && credit.coverage"
                :text="credit.coverage.reason"
                label="Detalle de cobertura"
            >
                <span
                    class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                    :class="coverageChip.cls"
                    >{{ coverageChip.label }}</span
                >
            </FinInfoTip>
            <span class="ml-auto text-[11px] text-muted-foreground">{{
                PERIODICITY_LABEL[credit.credit.periodicity] ??
                credit.credit.periodicity
            }}</span>
        </header>

        <!-- Sin tabla -->
        <div
            v-if="!credit.has_table"
            class="flex items-center gap-3 rounded-xl border border-dashed border-amber-300 bg-amber-50/60 p-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <FileSpreadsheet class="size-6 shrink-0" />
            <p class="flex-1">
                Sin tabla de amortización: el interés queda
                <b>sin información</b>.
            </p>
            <Button
                size="sm"
                variant="outline"
                class="h-7"
                @click="open.archivo = true"
                >Subir</Button
            >
        </div>

        <!-- Indicadores -->
        <div v-else class="grid items-center gap-3 sm:grid-cols-[auto_1fr]">
            <div class="flex items-center gap-3">
                <svg
                    viewBox="0 0 64 64"
                    class="size-16 -rotate-90"
                    role="img"
                    :aria-label="`${ring.done} de ${ring.expected} pagos conciliados`"
                >
                    <circle
                        cx="32"
                        cy="32"
                        :r="ring.r"
                        fill="none"
                        stroke-width="7"
                        class="stroke-muted"
                    />
                    <circle
                        v-if="ring.dash > 0"
                        cx="32"
                        cy="32"
                        :r="ring.r"
                        fill="none"
                        stroke-width="7"
                        stroke-linecap="round"
                        class="stroke-emerald-500 transition-[stroke-dasharray] duration-700"
                        :stroke-dasharray="`${ring.dash} ${ring.len}`"
                    />
                </svg>
                <div class="text-xs">
                    <p class="text-lg leading-none font-extrabold tabular-nums">
                        {{ ring.done
                        }}<span class="text-muted-foreground"
                            >/{{ ring.expected }}</span
                        >
                    </p>
                    <p class="text-muted-foreground">pagos conciliados</p>
                </div>
            </div>

            <dl
                class="grid grid-cols-2 gap-2 text-xs md:grid-cols-4 xl:grid-cols-2 2xl:grid-cols-4"
            >
                <div class="rounded-xl bg-rose-50/70 p-2 dark:bg-rose-950/25">
                    <dt class="text-[11px] text-muted-foreground">Interés</dt>
                    <dd
                        class="font-extrabold text-rose-700 tabular-nums dark:text-rose-400"
                    >
                        {{ mxn(recognized.interest) }}
                    </dd>
                </div>
                <div
                    v-if="hasCommission"
                    class="rounded-xl bg-rose-50/70 p-2 dark:bg-rose-950/25"
                >
                    <dt
                        class="flex items-center gap-1 text-[11px] text-muted-foreground"
                    >
                        Comisión financiera
                        <FinInfoTip
                            text="Comisión financiera (criterio por confirmar con Finanzas). No es interés; su IVA va separado."
                            label="Criterio de la comisión"
                        />
                    </dt>
                    <dd
                        class="font-extrabold text-rose-600 tabular-nums dark:text-rose-300"
                    >
                        {{ mxn(recognized.commission) }}
                    </dd>
                </div>
                <div class="rounded-xl bg-muted/40 p-2">
                    <dt
                        class="flex items-center gap-1 text-[11px] text-muted-foreground"
                    >
                        <CalendarClock class="size-3" /> Próximo pago
                    </dt>
                    <dd class="font-bold tabular-nums">
                        <template v-if="status?.next_payment">
                            {{ dmy(status.next_payment.date) }}
                            <span
                                class="block text-[11px] font-normal text-muted-foreground"
                            >
                                {{ mxn(status.next_payment.payment) }} ·
                                {{ status.remaining_payments }} restantes
                            </span>
                        </template>
                        <template v-else>—</template>
                    </dd>
                </div>
                <div class="rounded-xl bg-muted/40 p-2">
                    <dt
                        class="flex items-center gap-1 text-[11px] text-muted-foreground"
                    >
                        Saldo según tabla
                        <FinInfoTip
                            :text="
                                status?.document_balance_note ??
                                'Saldo según la tabla del acreedor: no es saldo bancario.'
                            "
                            label="No es saldo bancario"
                        />
                    </dt>
                    <dd class="font-bold tabular-nums">
                        {{
                            status?.document_balance
                                ? mxn(status.document_balance)
                                : '—'
                        }}
                    </dd>
                </div>
            </dl>
        </div>

        <!-- Avisos breves -->
        <p
            v-if="manualDiff"
            class="flex items-center gap-1.5 text-[11px] font-medium text-amber-800 dark:text-amber-300"
        >
            <AlertTriangle class="size-3.5 shrink-0" />
            Captura manual en Ajustes: difiere {{ mxn(manualDiff) }} de la
            tabla.
            <button
                type="button"
                class="font-semibold underline"
                @click="emit('goAdjustments')"
            >
                Ver
            </button>
        </p>
        <p
            v-if="credit.frozen?.outdated"
            class="flex items-center gap-1.5 rounded-xl border border-amber-300 bg-amber-50 p-2 text-[11px] font-medium text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <AlertTriangle class="size-3.5 shrink-0" />
            Versión {{ credit.frozen.version }} aprobada:
            {{
                credit.frozen.amount === null ? '—' : mxn(credit.frozen.amount)
            }}
            · con la tabla vigente:
            {{
                credit.frozen.live_amount === null
                    ? '—'
                    : mxn(credit.frozen.live_amount)
            }}
            <FinInfoTip
                text="Abre una nueva versión del periodo para aplicar la tabla vigente."
            />
        </p>

        <!-- Aportaciones por sucursal (chips) -->
        <div
            v-if="contributions?.by_branch.length"
            class="flex flex-wrap items-center gap-1.5"
        >
            <HandCoins class="size-3.5 text-cyan-600 dark:text-cyan-400" />
            <span
                v-for="b in contributions.by_branch"
                :key="b.branch"
                class="rounded-full bg-cyan-50 px-2 py-0.5 text-[11px] text-cyan-900 tabular-nums dark:bg-cyan-950/50 dark:text-cyan-200"
                >{{ b.branch }} · {{ mxn(b.amount) }}</span
            >
            <span
                v-if="num(contributions.pending) !== 0"
                class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-900 tabular-nums dark:bg-amber-950/50 dark:text-amber-200"
                >Pendiente · {{ mxn(contributions.pending) }}</span
            >
        </div>

        <!-- Detalle colapsable -->
        <div class="divide-y rounded-xl border">
            <Collapsible
                v-for="s in sections"
                :key="s.key"
                :open="!!open[s.key]"
                @update:open="(v: boolean) => (open[s.key] = v)"
            >
                <CollapsibleTrigger
                    class="group flex w-full items-center gap-2 px-3 py-2 text-xs font-semibold hover:bg-muted/40"
                >
                    <component
                        :is="s.icon"
                        class="size-3.5 text-muted-foreground"
                    />
                    {{ s.label }}
                    <span
                        v-if="s.badge"
                        class="rounded-full bg-muted px-1.5 text-[10px] tabular-nums"
                        >{{ s.badge }}</span
                    >
                    <ChevronDown
                        class="ml-auto size-3.5 transition-transform group-data-[state=open]:rotate-180"
                    />
                </CollapsibleTrigger>
                <CollapsibleContent class="px-3 pb-3">
                    <CreditorPaymentsList
                        v-if="s.key === 'pagos' && credit.creditor_payments"
                        :payments="credit.creditor_payments"
                        :show-commission="hasCommission"
                    />
                    <ul
                        v-else-if="s.key === 'aportaciones' && contributions"
                        class="space-y-1 text-[11px]"
                    >
                        <li
                            v-for="c in contributions.items"
                            :key="c.fingerprint"
                            class="flex items-center gap-2 rounded-lg bg-muted/40 px-2 py-1"
                        >
                            <span
                                class="size-2 shrink-0 rounded-full"
                                :class="
                                    c.status === 'identificada'
                                        ? 'bg-emerald-500'
                                        : 'bg-amber-500'
                                "
                                :title="
                                    c.status === 'identificada'
                                        ? 'Identificada'
                                        : (c.reason ?? 'Pendiente')
                                "
                            />
                            <span class="tabular-nums">{{ dmy(c.date) }}</span>
                            <span class="min-w-0 flex-1 truncate">{{
                                c.branch ?? 'Sin sucursal'
                            }}</span>
                            <span class="font-semibold tabular-nums">{{
                                mxn(c.amount)
                            }}</span>
                        </li>
                    </ul>
                    <AmortizationScheduleTable
                        v-else-if="s.key === 'tabla'"
                        :credit="credit"
                    />
                    <AmortizationUploadCard
                        v-else-if="s.key === 'archivo'"
                        :credit="credit"
                        :accept="accept"
                        :can-upload="canUpload"
                        :uploading="uploading"
                        @upload="(f, k) => emit('upload', f, k)"
                        @template="emit('template')"
                    />
                </CollapsibleContent>
            </Collapsible>
        </div>
    </article>
</template>
