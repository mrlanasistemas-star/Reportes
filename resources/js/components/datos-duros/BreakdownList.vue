<script setup lang="ts">
/** BreakdownList — lista de conceptos con importe y barra proporcional (desgloses de la ficha). */
import { computed } from 'vue';
import { money } from '@/lib/format';

const props = withDefaults(
    defineProps<{
        items: {
            label: string;
            value: number;
            hint?: string | null;
            color?: string;
        }[];
        empty?: string;
        barClass?: string;
    }>(),
    { empty: 'Sin movimientos en el periodo.', barClass: 'bg-primary/70' },
);

const visible = computed(() =>
    props.items.filter((i) => Math.abs(i.value) > 0.004),
);
const max = computed(() =>
    Math.max(1, ...visible.value.map((i) => Math.abs(i.value))),
);
</script>

<template>
    <ul v-if="visible.length" class="space-y-2.5">
        <li v-for="item in visible" :key="item.label">
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="min-w-0 truncate text-foreground">
                    {{ item.label }}
                    <span v-if="item.hint" class="text-xs text-muted-foreground"
                        >· {{ item.hint }}</span
                    >
                </span>
                <span
                    class="shrink-0 font-semibold text-foreground tabular-nums"
                    >{{ money(item.value) }}</span
                >
            </div>
            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    :class="[
                        'h-full rounded-full transition-all duration-500',
                        item.color ?? barClass,
                    ]"
                    :style="{
                        width: `${Math.max(2, (Math.abs(item.value) / max) * 100)}%`,
                    }"
                />
            </div>
        </li>
    </ul>
    <p
        v-else
        class="rounded-xl border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground"
    >
        {{ empty }}
    </p>
</template>
