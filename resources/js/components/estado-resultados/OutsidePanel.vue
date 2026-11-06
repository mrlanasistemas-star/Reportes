<script setup lang="ts">
import {
    ArrowLeftRight,
    Banknote,
    Building,
    CircleSlash,
    Copy,
    HandCoins,
    Hourglass,
    Landmark,
    Receipt,
    ShieldCheck,
    Undo2,
    UserRound,
} from 'lucide-vue-next';
import { mxn } from './types';
import type { Dataset } from './types';

defineProps<{ outside: Dataset['outside'] }>();

const ICONS: Record<string, unknown> = {
    capex: Building,
    capital_deuda: Landmark,
    transferencia_interna: ArrowLeftRight,
    personal_socios: UserRound,
    recuperable: Undo2,
    balance: Receipt,
    puente_terceros: HandCoins,
    cubierto_radiografia: ShieldCheck,
    conciliado_erp: Copy,
    duplicado: Copy,
    fuera_periodo: CircleSlash,
    pendiente: Hourglass,
};
</script>

<template>
    <div class="space-y-4">
        <div
            v-if="!outside.groups.length"
            class="flex flex-col items-center gap-2 rounded-2xl border border-dashed py-14 text-center text-sm text-muted-foreground"
        >
            <Banknote class="size-8" />
            <p class="font-semibold">
                No hay movimientos fuera del Estado de Resultados
            </p>
            <p class="text-xs">
                Carga los estados de cuenta complementarios para ver
                transferencias, CAPEX, deuda y movimientos personales.
            </p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="g in outside.groups"
                :key="g.key"
                class="flex flex-col gap-2 rounded-2xl border bg-card p-4 shadow-soft-xs"
                :class="
                    g.key === 'pendiente'
                        ? 'border-amber-300 dark:border-amber-900'
                        : ''
                "
            >
                <div class="flex items-center gap-2">
                    <div
                        class="flex size-9 items-center justify-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        <component
                            :is="ICONS[g.key] ?? CircleSlash"
                            class="size-4.5"
                        />
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold">
                            {{ g.label }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ g.count }} movimiento(s)
                        </p>
                    </div>
                </div>
                <div class="flex gap-4 text-sm">
                    <div>
                        <p class="text-[10px] text-muted-foreground uppercase">
                            Cargos
                        </p>
                        <p class="font-extrabold tabular-nums">
                            {{ mxn(g.cargos) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-[10px] text-muted-foreground uppercase">
                            Abonos
                        </p>
                        <p class="font-extrabold tabular-nums">
                            {{ mxn(g.abonos) }}
                        </p>
                    </div>
                </div>
                <p class="text-xs leading-relaxed text-muted-foreground">
                    {{ g.explain }}
                </p>
            </div>
        </div>

        <div class="rounded-2xl border bg-card p-4 shadow-soft-xs">
            <p
                class="mb-2 text-xs font-bold tracking-wide text-muted-foreground uppercase"
            >
                Flujos capturados en Ajustes (no afectan el resultado)
            </p>
            <div class="grid gap-2 sm:grid-cols-2">
                <div
                    v-for="f in outside.manual_flows"
                    :key="f.key"
                    class="flex items-center justify-between gap-2 rounded-xl bg-muted/40 p-3 text-sm"
                >
                    <div>
                        <p class="font-semibold">
                            {{ f.label }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ f.effect }}
                        </p>
                    </div>
                    <p class="font-extrabold tabular-nums">
                        {{
                            f.state === 'sin_informacion'
                                ? 'Sin información'
                                : mxn(f.amount)
                        }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
