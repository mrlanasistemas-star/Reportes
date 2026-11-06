<script setup lang="ts">
import {
    Building2,
    Crown,
    Landmark,
    Store,
    TrendingUp,
    Trophy,
} from 'lucide-vue-next';
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { compact, isNegative, mxn, pct } from './types';
import type { Dataset } from './types';

const props = defineProps<{ dataset: Dataset }>();

const cards = computed(() => {
    const r = props.dataset.results;
    const m = props.dataset.margins;
    const c = props.dataset.completeness;
    const consolidated = props.dataset.meta.scope.consolidated;
    const list = [
        {
            key: 'ingresos',
            label: 'Ingresos operativos',
            value: r.ingresos,
            margin: null as string | null,
            icon: TrendingUp,
            accent: 'from-emerald-500 to-emerald-600',
            missing: [] as string[],
        },
        {
            key: 'ebitda_sucursales',
            label: 'EBITDA sucursales',
            value: r.ebitda_sucursales,
            margin: m.ebitda_sucursales,
            icon: Store,
            accent: 'from-teal-600 to-cyan-500',
            missing: [],
        },
    ];

    if (consolidated) {
        list.push(
            {
                key: 'ebitda_corporativo',
                label: 'EBITDA corporativo',
                value: r.ebitda_corporativo,
                margin: m.ebitda_corporativo,
                icon: Building2,
                accent: 'from-cyan-600 to-sky-500',
                missing: [],
            },
            {
                key: 'ebitda_final',
                label: 'EBITDA final',
                value: r.ebitda_final,
                margin: m.ebitda_final,
                icon: Crown,
                accent: 'from-teal-800 to-teal-600',
                missing: [],
            },
        );
    }

    list.push(
        {
            key: 'ebit',
            label: 'EBIT',
            value: r.ebit,
            margin: m.ebit,
            icon: Landmark,
            accent: 'from-slate-600 to-slate-500',
            missing: c.ebit?.missing ?? [],
        },
        {
            key: 'utilidad_neta',
            label: 'Utilidad neta',
            value: r.utilidad_neta,
            margin: m.neto,
            icon: Trophy,
            accent: 'from-emerald-700 to-cyan-500',
            missing: c.utilidad_neta?.missing ?? [],
        },
    );

    return list;
});
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
        <div
            v-for="(k, i) in cards"
            :key="k.key"
            class="er-fade-up relative overflow-hidden rounded-2xl border bg-card p-4 shadow-soft-xs transition hover:-translate-y-0.5 hover:shadow-md"
            :style="{ animationDelay: `${i * 45}ms` }"
        >
            <div
                class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r"
                :class="k.accent"
            />
            <div class="flex items-center justify-between gap-2">
                <p
                    class="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ k.label }}
                </p>
                <component
                    :is="k.icon"
                    class="size-4 text-teal-600 dark:text-teal-400"
                />
            </div>
            <Tooltip>
                <TooltipTrigger as-child>
                    <p
                        class="mt-2 truncate text-2xl font-extrabold tracking-tight tabular-nums"
                        :class="
                            isNegative(k.value)
                                ? 'text-rose-600'
                                : 'text-foreground'
                        "
                    >
                        {{ compact(k.value) }}
                    </p>
                </TooltipTrigger>
                <TooltipContent>{{ mxn(k.value) }}</TooltipContent>
            </Tooltip>
            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                <span
                    v-if="k.margin !== null"
                    class="font-semibold"
                    :class="
                        isNegative(k.margin)
                            ? 'text-rose-600'
                            : 'text-emerald-700 dark:text-emerald-400'
                    "
                    >Margen {{ pct(k.margin) }}</span
                >
                <Tooltip v-if="k.missing.length">
                    <TooltipTrigger as-child>
                        <span
                            class="cursor-help rounded-full border border-amber-200 bg-amber-50 px-1.5 py-px text-[10px] font-bold text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                            >Preliminar</span
                        >
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">
                        Falta información (no se asume $0):
                        {{ k.missing.join(', ') }}
                    </TooltipContent>
                </Tooltip>
            </div>
        </div>
    </div>
</template>
