<script setup lang="ts">
import { computed } from 'vue';
import { num } from './types';

/** Barra del peso de una línea sobre los ingresos (sólo visual; el % exacto va en su columna). */
const props = defineProps<{
    pct: string | number | null | undefined;
    tone?: 'cost' | 'income' | 'result' | 'muted';
    strong?: boolean;
}>();

const width = computed(() => Math.min(100, Math.abs(num(props.pct))));
const TONES: Record<string, string> = {
    cost: 'bg-rose-400/70 dark:bg-rose-400/60',
    income: 'bg-emerald-500/70 dark:bg-emerald-400/60',
    result: 'bg-white/60',
    muted: 'bg-muted-foreground/30',
};
</script>

<template>
    <span class="hidden h-full items-center xl:flex" aria-hidden="true">
        <span
            v-if="pct !== null && pct !== undefined && width > 0"
            class="block rounded-full transition-[width] duration-500"
            :class="[TONES[tone ?? 'muted'], strong ? 'h-2' : 'h-1.5']"
            :style="{ width: `max(${width}%, 2px)` }"
        />
    </span>
</template>
