<script setup lang="ts">
import {
    ArrowLeftRight,
    ArrowUpRight,
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
import { computed } from 'vue';
import { mxn, num } from './types';
import type { Dataset } from './types';

const props = defineProps<{ outside: Dataset['outside'] }>();
const emit = defineEmits<{ openMovements: [filterKey: string] }>();

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

/** Barras proporcionales al volumen (cargos + abonos) de cada grupo; sólo visual. */
const groups = computed(() => {
    const vol = (g: { cargos: string; abonos: string }) =>
        Math.abs(num(g.cargos)) + Math.abs(num(g.abonos));
    const sorted = [...props.outside.groups].sort((a, b) => vol(b) - vol(a));
    const max = Math.max(1, ...sorted.map(vol));

    return sorted.map((g) => ({
        ...g,
        cargoW: (Math.abs(num(g.cargos)) / max) * 100,
        abonoW: (Math.abs(num(g.abonos)) / max) * 100,
    }));
});
</script>

<template>
    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(300px,0.42fr)]">
        <section class="rounded-2xl border bg-card shadow-soft-xs">
            <header class="border-b px-5 py-4">
                <h3 class="text-base font-extrabold">
                    Lo que salió o entró al banco y no es resultado
                </h3>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Se conserva para que el banco cuadre completo, pero no es
                    ingreso ni gasto del periodo. Haz clic en un renglón para
                    ver sus movimientos.
                </p>
            </header>
            <div
                v-if="!groups.length"
                class="flex flex-col items-center gap-2 px-6 py-14 text-center"
            >
                <Banknote class="size-8 text-muted-foreground/60" />
                <p class="text-sm font-semibold">
                    Nada fuera del Estado de Resultados por ahora
                </p>
                <p class="max-w-sm text-xs text-muted-foreground">
                    Al cargar y procesar los estados de cuenta aquí verás
                    transferencias, CAPEX, deuda y movimientos personales.
                </p>
            </div>
            <ul v-else class="divide-y">
                <li v-for="g in groups" :key="g.key">
                    <button
                        type="button"
                        class="group grid w-full grid-cols-[2.25rem_minmax(0,1fr)] gap-x-3 gap-y-2 px-5 py-3.5 text-left transition hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-none md:grid-cols-[2.25rem_minmax(0,1fr)_minmax(0,1.1fr)_auto]"
                        @click="emit('openMovements', `t:${g.key}`)"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-xl"
                            :class="
                                g.key === 'pendiente'
                                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'
                                    : 'bg-muted text-foreground/80'
                            "
                        >
                            <component
                                :is="ICONS[g.key] ?? CircleSlash"
                                class="size-4.5"
                            />
                        </span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-bold">{{
                                g.label
                            }}</span>
                            <span
                                class="line-clamp-2 text-xs text-muted-foreground"
                                >{{ g.explain }}</span
                            >
                        </span>
                        <span
                            class="col-span-2 space-y-1 md:col-span-1 md:self-center"
                        >
                            <span class="flex items-center gap-2 text-[11px]">
                                <span
                                    class="w-12 shrink-0 text-muted-foreground"
                                    >Cargos</span
                                >
                                <span
                                    class="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                                >
                                    <span
                                        class="block h-full rounded-full bg-slate-700 transition-[width] duration-700 dark:bg-slate-300"
                                        :style="{ width: `${g.cargoW}%` }"
                                    />
                                </span>
                                <span
                                    class="w-28 shrink-0 text-right font-semibold tabular-nums"
                                    >{{ mxn(g.cargos) }}</span
                                >
                            </span>
                            <span class="flex items-center gap-2 text-[11px]">
                                <span
                                    class="w-12 shrink-0 text-muted-foreground"
                                    >Abonos</span
                                >
                                <span
                                    class="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                                >
                                    <span
                                        class="block h-full rounded-full bg-sky-500 transition-[width] duration-700"
                                        :style="{ width: `${g.abonoW}%` }"
                                    />
                                </span>
                                <span
                                    class="w-28 shrink-0 text-right font-semibold tabular-nums"
                                    >{{ mxn(g.abonos) }}</span
                                >
                            </span>
                        </span>
                        <span
                            class="hidden items-center gap-1 self-center text-xs font-semibold text-muted-foreground group-hover:text-cyan-700 md:flex dark:group-hover:text-cyan-300"
                        >
                            {{ g.count }} mov.
                            <ArrowUpRight
                                class="size-3.5 transition group-hover:translate-x-0.5 group-hover:-translate-y-0.5"
                            />
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <section class="h-fit rounded-2xl border bg-card p-5 shadow-soft-xs">
            <h3 class="text-base font-extrabold">
                Flujos capturados en ajustes
            </h3>
            <p class="mt-0.5 text-xs text-muted-foreground">
                Informativos: no cambian ningún resultado.
            </p>
            <ul class="mt-4 space-y-2">
                <li
                    v-for="f in outside.manual_flows"
                    :key="f.key"
                    class="flex items-center justify-between gap-3 rounded-xl bg-muted/50 p-3"
                >
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold">{{
                            f.label
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            f.effect
                        }}</span>
                    </span>
                    <span
                        class="shrink-0 text-sm font-extrabold tabular-nums"
                        :class="
                            f.state === 'sin_informacion'
                                ? 'text-amber-700 dark:text-amber-400'
                                : ''
                        "
                        >{{
                            f.state === 'sin_informacion'
                                ? 'Sin información'
                                : mxn(f.amount)
                        }}</span
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
