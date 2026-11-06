<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCountUp } from '@/composables/estado-resultados/useCountUp';
import { signed, signedCompact } from './comparativas';
import type { CompRow } from './comparativas';
import { compact, isNegative, mxn, num, pct } from './types';

const props = defineProps<{
    label: string;
    /** null = pendiente / sin dato (nunca $0). */
    value: string | null;
    unit: 'MXN' | '%';
    active?: boolean;
    emphasis?: boolean;
    pendingText?: string;
    missing?: string[];
    /** Fila de la comparativa contra el periodo anterior (si ya cargó). */
    delta?: CompRow | null;
    comparable?: boolean;
    deltaLoading?: boolean;
}>();

defineEmits<{ select: [] }>();

const shown = useCountUp(() => num(props.value));
const negative = computed(
    () => props.value !== null && isNegative(props.value),
);
const display = computed(() =>
    props.value === null
        ? null
        : props.unit === '%'
          ? `${shown.value.toFixed(2)}%`
          : compact(shown.value),
);
const exact = computed(() =>
    props.value === null
        ? null
        : props.unit === '%'
          ? pct(props.value)
          : mxn(props.value),
);
const trend = computed(() => {
    const d = props.delta;

    if (!d || d.variation === null || num(d.variation) === 0) {
        return null;
    }

    return num(d.variation) > 0 ? 'up' : 'down';
});
</script>

<template>
    <button
        type="button"
        class="group relative flex min-w-0 flex-col gap-1 px-3.5 py-3 text-left transition hover:bg-muted/50 focus-visible:z-10 focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none"
        :class="active ? 'bg-emerald-50/70 dark:bg-emerald-950/25' : ''"
        :aria-pressed="active"
        :aria-label="`${label}: ${exact ?? 'Pendiente'}`"
        @click="$emit('select')"
    >
        <span
            aria-hidden="true"
            class="absolute inset-x-3.5 top-0 h-0.5 origin-left rounded-full bg-cyan-500 transition-transform duration-300"
            :class="active ? 'scale-x-100' : 'scale-x-0'"
        />
        <span
            class="text-xs leading-tight font-semibold text-muted-foreground"
            >{{ label }}</span
        >
        <span
            v-if="display !== null"
            class="truncate text-[1.35rem] leading-none font-extrabold tracking-tight tabular-nums"
            :class="[
                negative ? 'text-rose-600 dark:text-rose-400' : '',
                emphasis && !negative
                    ? 'text-emerald-800 dark:text-emerald-300'
                    : '',
            ]"
            :title="exact ?? ''"
        >
            {{ display }}
        </span>
        <Tooltip v-else>
            <TooltipTrigger as-child>
                <span
                    class="inline-flex w-fit cursor-help items-center rounded-lg border border-dashed border-amber-400 px-2 py-1 text-sm font-bold text-amber-800 dark:text-amber-300"
                    >{{ pendingText ?? 'Pendiente' }}</span
                >
            </TooltipTrigger>
            <TooltipContent class="max-w-xs">
                {{
                    missing?.length
                        ? `Falta: ${missing.join(', ')}. No se asume $0.`
                        : unit === '%'
                          ? 'Sin margen: los ingresos son $0.00 o el resultado está pendiente.'
                          : 'Sin información: no se presenta como $0.00.'
                }}
            </TooltipContent>
        </Tooltip>

        <!-- variación vs periodo anterior -->
        <span class="min-h-4 text-[11px] tabular-nums">
            <span v-if="deltaLoading" class="text-muted-foreground"
                >Comparando…</span
            >
            <Tooltip v-else-if="delta && !comparable">
                <TooltipTrigger as-child>
                    <span
                        class="cursor-help text-muted-foreground underline decoration-dotted"
                        >No comparable</span
                    >
                </TooltipTrigger>
                <TooltipContent class="max-w-xs">
                    {{
                        delta.reason?.message ??
                        'Los periodos no son comparables.'
                    }}
                </TooltipContent>
            </Tooltip>
            <span
                v-else-if="delta && delta.variation !== null"
                class="inline-flex flex-wrap items-center gap-x-0.5 font-semibold"
                :class="
                    delta.assessment === 'favorable'
                        ? 'text-emerald-700 dark:text-emerald-400'
                        : delta.assessment === 'desfavorable'
                          ? 'text-rose-600 dark:text-rose-400'
                          : 'text-muted-foreground'
                "
                :title="delta.reason?.message ?? delta.assessment_label ?? ''"
            >
                <ArrowUpRight v-if="trend === 'up'" class="size-3" />
                <ArrowDownRight v-else-if="trend === 'down'" class="size-3" />
                <Minus v-else class="size-3" />
                {{ signedCompact(delta.variation, delta.variation_unit) }}
                <span v-if="delta.variation_pct !== null" class="opacity-80"
                    >({{ signed(delta.variation_pct, '%') }})</span
                >
            </span>
            <span
                v-else-if="delta"
                class="text-muted-foreground"
                :title="delta.reason?.message ?? ''"
                >Sin comparación</span
            >
        </span>
    </button>
</template>
