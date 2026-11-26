<script setup lang="ts">
/**
 * TotalsStrip — totales del conjunto FILTRADO (cambian con cada letra o chip).
 * Son sumas de las filas de colaboradores: con filtros activos representan solo
 * a las personas visibles, no el consolidado de la empresa.
 */
import {
    Banknote,
    HandCoins,
    Landmark,
    PiggyBank,
    Receipt,
    TrendingUp,
    Users,
    Wallet,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Skeleton } from '@/components/ui/skeleton';
import { moneyCompact, money, num, percent } from '@/lib/format';
import { moraTone } from '@/lib/hardData';

const props = defineProps<{
    totals: {
        count: number;
        active: number;
        placement: number;
        operations: number;
        recovery: number;
        portfolio: number;
        overdue: number;
        moraPct: number;
        payroll: number;
        opex: number;
        ebitda: number;
        marginPct: number;
    };
    loading?: boolean;
}>();

const TONES = {
    success: 'bg-success-soft text-success',
    warning: 'bg-warning-soft text-warning',
    danger: 'bg-danger-soft text-danger',
    info: 'bg-info-soft text-info',
    neutral: 'bg-muted text-muted-foreground',
} as const;

const tiles = computed(() => {
    const t = props.totals;

    return [
        {
            label: 'Colaboradores',
            value: num(t.count),
            full: `${num(t.active)} activos`,
            hint: `${num(t.active)} activos`,
            icon: Users,
            tone: 'neutral' as const,
        },
        {
            label: 'Colocación',
            value: moneyCompact(t.placement),
            full: money(t.placement),
            hint: `${num(t.operations)} operaciones`,
            icon: HandCoins,
            tone: 'info' as const,
        },
        {
            label: 'Recuperación',
            value: moneyCompact(t.recovery),
            full: money(t.recovery),
            hint: 'Cobranza del periodo',
            icon: Banknote,
            tone: 'success' as const,
        },
        {
            label: 'Cartera',
            value: moneyCompact(t.portfolio),
            full: money(t.portfolio),
            hint: `Vencida ${moneyCompact(t.overdue)}`,
            icon: Wallet,
            tone: 'info' as const,
        },
        {
            label: 'Mora',
            value: percent(t.moraPct),
            full: `Vencida ${money(t.overdue)}`,
            hint: 'Vencida / cartera',
            icon: TrendingUp,
            tone: moraTone(t.moraPct),
        },
        {
            label: 'Nómina',
            value: moneyCompact(t.payroll),
            full: money(t.payroll),
            hint: 'Capital humano',
            icon: Receipt,
            tone: 'neutral' as const,
        },
        {
            label: 'OPEX',
            value: moneyCompact(t.opex),
            full: money(t.opex),
            hint: 'Gasto atribuible',
            icon: PiggyBank,
            tone: 'warning' as const,
        },
    ];
});
</script>

<template>
    <section
        aria-label="Totales del filtro"
        class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-9"
    >
        <article
            class="relative col-span-2 overflow-hidden rounded-2xl border border-transparent bg-gradient-to-br from-primary via-primary/90 to-primary/75 p-4 text-primary-foreground shadow-soft-md sm:col-span-4 xl:col-span-2"
        >
            <div
                class="pointer-events-none absolute -top-10 -right-8 size-32 rounded-full bg-white/10 blur-2xl"
                aria-hidden="true"
            />
            <div
                class="flex items-center gap-2 text-[11px] font-bold tracking-wide uppercase opacity-90"
            >
                <Landmark class="size-4" aria-hidden="true" /> EBITDA
            </div>
            <Skeleton v-if="loading" class="mt-3 h-8 w-36 bg-white/20" />
            <template v-else>
                <p
                    class="mt-2 text-3xl font-extrabold tracking-tight tabular-nums"
                    :title="money(totals.ebitda)"
                >
                    {{ moneyCompact(totals.ebitda) }}
                </p>
                <p class="mt-1 text-xs opacity-85">
                    Margen
                    <span class="font-bold tabular-nums">{{
                        percent(totals.marginPct)
                    }}</span>
                    sobre ingreso base
                </p>
            </template>
        </article>

        <article
            v-for="tile in tiles"
            :key="tile.label"
            class="app-hover-lift flex flex-col rounded-2xl border border-border bg-card p-3.5 shadow-soft-xs"
            :title="tile.full"
        >
            <div class="flex items-center gap-2">
                <span
                    :class="[
                        'flex size-7 items-center justify-center rounded-lg',
                        TONES[tile.tone],
                    ]"
                    aria-hidden="true"
                >
                    <component :is="tile.icon" class="size-3.5" />
                </span>
                <span
                    class="text-[11px] font-bold tracking-wide text-muted-foreground uppercase"
                    >{{ tile.label }}</span
                >
            </div>
            <Skeleton v-if="loading" class="mt-3 h-6 w-20" />
            <template v-else>
                <p
                    class="mt-2 text-xl font-extrabold tracking-tight text-foreground tabular-nums"
                >
                    {{ tile.value }}
                </p>
                <p class="mt-0.5 truncate text-[11px] text-muted-foreground">
                    {{ tile.hint }}
                </p>
            </template>
        </article>
    </section>
</template>
