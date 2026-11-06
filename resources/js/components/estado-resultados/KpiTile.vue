<script setup lang="ts">
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCountUp } from '@/composables/estado-resultados/useCountUp';
import { compact, isNegative, mxn, num, pct } from './types';

const props = defineProps<{
    label: string;
    tier: string | null;
    value: string;
    margin: string | null;
    missing: string[];
    active: boolean;
    emphasis?: boolean;
    note?: { tone: 'ok' | 'warn'; text: string; title: string } | null;
}>();

defineEmits<{ select: [] }>();

const shown = useCountUp(() => num(props.value));
const negative = computed(() => isNegative(props.value));
const marginWidth = computed(() =>
    props.margin === null ? 0 : Math.min(100, Math.abs(num(props.margin))),
);
</script>

<template>
    <button
        type="button"
        class="group relative flex min-w-0 flex-col gap-1 px-3.5 py-3.5 text-left transition hover:bg-muted/50 focus-visible:z-10 focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none"
        :class="active ? 'bg-emerald-50/70 dark:bg-emerald-950/25' : ''"
        :aria-pressed="active"
        :aria-label="`${label}: ${mxn(value)}. Ver en la cascada`"
        @click="$emit('select')"
    >
        <span
            aria-hidden="true"
            class="absolute inset-x-4 top-0 h-0.5 origin-left rounded-full bg-cyan-500 transition-transform duration-300"
            :class="active ? 'scale-x-100' : 'scale-x-0'"
        />
        <span class="flex items-center gap-1.5 text-xs text-muted-foreground">
            <span
                v-if="tier"
                class="flex size-4 shrink-0 items-center justify-center rounded-full bg-emerald-700 text-[10px] font-bold text-white dark:bg-emerald-500 dark:text-emerald-950"
                :title="`EBITDA nivel ${tier}`"
                >{{ tier }}</span
            >
            <span class="truncate font-semibold">{{ label }}</span>
        </span>
        <span
            class="truncate text-2xl leading-none font-extrabold tracking-tight tabular-nums"
            :class="[
                negative ? 'text-rose-600 dark:text-rose-400' : '',
                emphasis && !negative
                    ? 'text-emerald-800 dark:text-emerald-300'
                    : '',
            ]"
        >
            {{ compact(shown) }}
        </span>
        <span class="truncate text-[11px] text-muted-foreground tabular-nums">{{
            mxn(value)
        }}</span>
        <span class="mt-1 flex items-center gap-2">
            <span class="h-1 flex-1 overflow-hidden rounded-full bg-muted">
                <span
                    class="block h-full rounded-full transition-[width] duration-700"
                    :class="negative ? 'bg-rose-500' : 'bg-emerald-500'"
                    :style="{ width: `${marginWidth}%` }"
                />
            </span>
            <span
                class="shrink-0 text-[11px] font-semibold tabular-nums"
                :class="
                    margin === null
                        ? 'text-muted-foreground'
                        : negative
                          ? 'text-rose-600 dark:text-rose-400'
                          : 'text-emerald-700 dark:text-emerald-400'
                "
                :title="
                    margin === null
                        ? 'Sin margen: no hay ingresos en el periodo o alcance.'
                        : 'Margen sobre ingresos operativos'
                "
                >{{ margin === null ? 'sin margen' : pct(margin) }}</span
            >
        </span>
        <span class="flex min-h-5 flex-wrap items-center gap-1">
            <Tooltip v-if="missing.length">
                <TooltipTrigger as-child>
                    <span
                        class="cursor-help rounded-full border border-amber-300 bg-amber-50 px-1.5 py-px text-[10px] font-bold text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
                        >Preliminar · faltan {{ missing.length }}</span
                    >
                </TooltipTrigger>
                <TooltipContent class="max-w-xs">
                    No se asume $0. Falta: {{ missing.join(', ') }}.
                </TooltipContent>
            </Tooltip>
            <Tooltip v-if="note">
                <TooltipTrigger as-child>
                    <span
                        class="cursor-help rounded-full border px-1.5 py-px text-[10px] font-bold"
                        :class="
                            note.tone === 'ok'
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
                                : 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300'
                        "
                        >{{ note.text }}</span
                    >
                </TooltipTrigger>
                <TooltipContent class="max-w-xs">{{
                    note.title
                }}</TooltipContent>
            </Tooltip>
        </span>
    </button>
</template>
