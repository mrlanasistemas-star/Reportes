<script setup lang="ts">
/**
 * CollaboratorSheet — ficha completa de un colaborador en el periodo: KPIs,
 * EBITDA (ingreso base − gastos), recuperación por componente, cartera por
 * antigüedad, colocación por producto, nómina por concepto, OPEX atribuible y
 * evolución contra otros periodos. Todo sale de la fila ya cargada (sin
 * llamadas extra), salvo la evolución.
 */
import { Building2, IdCard, MapPin } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useInitials } from '@/composables/useInitials';
import { moneyCompact, money, num, percent } from '@/lib/format';
import {
    BUCKET_COLORS,
    BUCKET_LABELS,
    PAYROLL_LABELS,
    RECOVERY_LABELS,
    moraTone,
} from '@/lib/hardData';
import type { HardDataPeriod, HardDataRow } from '@/types/hardData';
import BreakdownList from './BreakdownList.vue';
import CategoryBadge from './CategoryBadge.vue';
import HistoryTrend from './HistoryTrend.vue';

const props = defineProps<{
    row: HardDataRow | null;
    period: HardDataPeriod | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const { getInitials } = useInitials();

const r = computed(() => props.row);
const TONE_TEXT = {
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
} as const;

const kpis = computed(() => {
    const x = r.value;

    if (!x) {
        return [];
    }

    return [
        {
            label: 'Colocación',
            value: moneyCompact(x.placement),
            full: money(x.placement),
            hint: `${num(x.operations)} operaciones`,
        },
        {
            label: 'Recuperación',
            value: moneyCompact(x.recovery),
            full: money(x.recovery),
            hint: 'Cobranza del periodo',
        },
        {
            label: 'Cartera',
            value: moneyCompact(x.portfolio),
            full: money(x.portfolio),
            hint: `Al corriente ${moneyCompact(x.current)}`,
        },
        {
            label: 'Mora',
            value: percent(x.mora_pct),
            full: `Vencida ${money(x.overdue)}`,
            hint: `Vencida ${moneyCompact(x.overdue)}`,
            tone: moraTone(x.mora_pct),
        },
        {
            label: 'Nómina',
            value: moneyCompact(x.payroll),
            full: money(x.payroll),
            hint: `Comisiones ${moneyCompact(x.commissions)}`,
        },
        {
            label: 'OPEX',
            value: moneyCompact(x.opex),
            full: money(x.opex),
            hint: `${x.opex_items.length} concepto(s)`,
        },
    ];
});

const recovery = computed(() =>
    Object.entries(r.value?.recovery_components ?? {}).map(([k, v]) => ({
        label: RECOVERY_LABELS[k] ?? k,
        value: Number(v),
    })),
);

const buckets = computed(() => {
    const entries = Object.entries(r.value?.buckets ?? {});
    const total = entries.reduce((a, [, b]) => a + Number(b.amount ?? 0), 0);

    return {
        total,
        items: entries.map(([k, b]) => ({
            key: k,
            label: BUCKET_LABELS[k] ?? k,
            contracts: b.contracts,
            amount: Number(b.amount ?? 0),
            pct: total > 0 ? (Number(b.amount ?? 0) / total) * 100 : 0,
            color: BUCKET_COLORS[k] ?? 'bg-muted-foreground',
        })),
    };
});

const products = computed(() =>
    [...(r.value?.placement_by_product ?? [])]
        .sort((a, b) => b.amount - a.amount)
        .map((p) => ({
            label: (p.product ?? 'Sin producto').toUpperCase(),
            value: p.amount,
            hint: `${num(p.operations)} ops`,
        })),
);

const payroll = computed(() =>
    Object.entries(r.value?.payroll_concepts ?? {}).map(([k, v]) => ({
        label: PAYROLL_LABELS[k] ?? k,
        value: Number(v),
    })),
);

const opex = computed(() =>
    (r.value?.opex_items ?? []).map((i) => ({
        label: i.concept,
        value: i.amount,
        hint: i.type === 'nomina_empleado' ? 'Nómina del empleado' : i.category,
        color: i.type === 'nomina_empleado' ? 'bg-info/70' : 'bg-warning/70',
    })),
);

const ebitdaParts = computed(() => {
    const x = r.value;

    if (!x) {
        return null;
    }

    const base = Math.max(1, Math.abs(x.income_base));

    return {
        expensesPct: Math.min(100, (Math.abs(x.total_expenses) / base) * 100),
    };
});
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
        >
            <template v-if="r">
                <SheetHeader
                    class="sticky top-0 z-10 border-b border-border bg-background/95 p-5 backdrop-blur"
                >
                    <div class="flex items-start gap-3 pr-6">
                        <span
                            class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-primary/70 text-base font-extrabold text-primary-foreground shadow-soft-sm"
                            >{{ getInitials(r.name) }}</span
                        >
                        <div class="min-w-0 flex-1">
                            <SheetTitle
                                class="text-lg leading-tight font-extrabold"
                                >{{ r.name }}</SheetTitle
                            >
                            <SheetDescription
                                class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs"
                            >
                                <span class="inline-flex items-center gap-1"
                                    ><Building2 class="size-3.5" />
                                    {{ r.branch ?? 'Sin sucursal' }}</span
                                >
                                <span
                                    v-if="r.routes.length"
                                    class="inline-flex items-center gap-1"
                                    ><MapPin class="size-3.5" />
                                    {{ r.routes.join(', ') }}</span
                                >
                                <span
                                    v-if="r.code || r.promoter_code"
                                    class="inline-flex items-center gap-1"
                                    ><IdCard class="size-3.5" />
                                    {{
                                        [r.code, r.promoter_code]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}</span
                                >
                            </SheetDescription>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-1.5"
                            >
                                <CategoryBadge
                                    :category="r.category"
                                    size="md"
                                />
                                <span
                                    :class="[
                                        'rounded-full px-2.5 py-1 text-xs font-semibold',
                                        (r.status ?? '').toUpperCase() ===
                                        'BAJA'
                                            ? 'bg-danger-soft text-danger'
                                            : 'bg-success-soft text-success',
                                    ]"
                                    >{{
                                        r.roster_label ??
                                        r.status ??
                                        'Sin estado'
                                    }}</span
                                >
                                <span
                                    v-if="period"
                                    class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                                    >{{ period.name }}</span
                                >
                            </div>
                            <div class="mt-3">
                                <slot name="actions" />
                            </div>
                        </div>
                    </div>
                </SheetHeader>

                <div class="space-y-6 p-5">
                    <!-- EBITDA -->
                    <section
                        class="rounded-2xl bg-gradient-to-br from-primary/10 via-primary/5 to-transparent p-4 ring-1 ring-primary/15"
                    >
                        <div class="flex items-end justify-between gap-3">
                            <div>
                                <p
                                    class="text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    EBITDA del colaborador
                                </p>
                                <p
                                    :class="[
                                        'text-3xl font-extrabold tracking-tight tabular-nums',
                                        r.ebitda < 0
                                            ? 'text-danger'
                                            : 'text-foreground',
                                    ]"
                                >
                                    {{ money(r.ebitda) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p
                                    class="text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                                >
                                    Margen
                                </p>
                                <p
                                    class="text-xl font-extrabold text-foreground tabular-nums"
                                >
                                    {{
                                        r.margin_pct === null
                                            ? '—'
                                            : percent(r.margin_pct)
                                    }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 space-y-1.5 text-sm">
                            <div class="flex justify-between">
                                <span class="text-muted-foreground"
                                    >Ingreso base</span
                                ><span class="font-semibold tabular-nums">{{
                                    money(r.income_base)
                                }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-muted-foreground"
                                    >− Gastos (nómina + OPEX)</span
                                ><span
                                    class="font-semibold text-danger tabular-nums"
                                    >{{ money(r.total_expenses) }}</span
                                >
                            </div>
                        </div>
                        <div
                            v-if="ebitdaParts"
                            class="mt-2 flex h-2 overflow-hidden rounded-full bg-success/70"
                            title="Proporción de gastos sobre el ingreso base"
                        >
                            <div
                                class="h-full bg-danger/70"
                                :style="{
                                    width: `${ebitdaParts.expensesPct}%`,
                                }"
                            />
                        </div>
                    </section>

                    <!-- KPIs -->
                    <section class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                        <div
                            v-for="k in kpis"
                            :key="k.label"
                            class="rounded-xl border border-border bg-card p-3"
                            :title="k.full"
                        >
                            <p class="text-[11px] text-muted-foreground">
                                {{ k.label }}
                            </p>
                            <p
                                :class="[
                                    'text-base font-extrabold tabular-nums',
                                    k.tone
                                        ? TONE_TEXT[k.tone]
                                        : 'text-foreground',
                                ]"
                            >
                                {{ k.value }}
                            </p>
                            <p
                                class="truncate text-[11px] text-muted-foreground"
                            >
                                {{ k.hint }}
                            </p>
                        </div>
                    </section>

                    <!-- Evolución -->
                    <section v-if="period">
                        <h3 class="mb-2 text-sm font-bold text-foreground">
                            Evolución
                        </h3>
                        <HistoryTrend
                            :employee-id="r.id"
                            :period-id="period.id"
                            :period-type="period.type"
                        />
                    </section>

                    <!-- Cartera por antigüedad -->
                    <section>
                        <h3 class="mb-2 text-sm font-bold text-foreground">
                            Cartera por antigüedad
                        </h3>
                        <template v-if="buckets.total > 0">
                            <div
                                class="flex h-3 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    v-for="b in buckets.items"
                                    :key="b.key"
                                    :class="['h-full', b.color]"
                                    :style="{ width: `${b.pct}%` }"
                                    :title="`${b.label}: ${money(b.amount)}`"
                                />
                            </div>
                            <ul
                                class="mt-3 grid grid-cols-1 gap-1.5 sm:grid-cols-2"
                            >
                                <li
                                    v-for="b in buckets.items"
                                    :key="b.key"
                                    class="flex items-center justify-between gap-2 rounded-lg px-2 py-1 text-xs hover:bg-muted/50"
                                >
                                    <span class="flex items-center gap-2"
                                        ><span
                                            :class="[
                                                'size-2.5 rounded-sm',
                                                b.color,
                                            ]"
                                        />{{ b.label }}
                                        <span class="text-muted-foreground"
                                            >·
                                            {{ num(b.contracts) }} ctos.</span
                                        ></span
                                    >
                                    <span class="font-semibold tabular-nums">{{
                                        money(b.amount)
                                    }}</span>
                                </li>
                            </ul>
                        </template>
                        <p
                            v-else
                            class="rounded-xl border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground"
                        >
                            Sin cartera en el periodo.
                        </p>
                    </section>

                    <section>
                        <h3 class="mb-2 text-sm font-bold text-foreground">
                            Recuperación por componente
                        </h3>
                        <BreakdownList
                            :items="recovery"
                            bar-class="bg-success/70"
                            empty="Sin recuperación en el periodo."
                        />
                    </section>

                    <section>
                        <h3 class="mb-2 text-sm font-bold text-foreground">
                            Colocación por producto
                        </h3>
                        <BreakdownList
                            :items="products"
                            bar-class="bg-info/70"
                            empty="Sin colocación en el periodo."
                        />
                    </section>

                    <section>
                        <h3 class="mb-2 text-sm font-bold text-foreground">
                            Nómina por concepto
                        </h3>
                        <BreakdownList
                            :items="payroll"
                            empty="Sin percepciones NOI en el periodo."
                        />
                        <p
                            v-if="r.deductions"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Percepciones NOI {{ money(r.perceptions) }} ·
                            deducciones {{ money(r.deductions) }}
                        </p>
                    </section>

                    <section>
                        <h3 class="mb-2 text-sm font-bold text-foreground">
                            OPEX atribuible
                        </h3>
                        <BreakdownList
                            :items="opex"
                            empty="Sin gastos atribuidos en el periodo."
                        />
                    </section>

                    <p
                        class="rounded-xl bg-muted/60 px-3 py-2.5 text-[11px] leading-relaxed text-muted-foreground"
                    >
                        Cifras oficiales de la radiografía vigente del periodo
                        (mismas fuentes que el Excel de colaboradores). No
                        incluyen ajustes manuales temporales del reporte.
                    </p>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
