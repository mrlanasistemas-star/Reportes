<script setup lang="ts">
import { computed } from 'vue';
import type { RunStatus, StageInfo } from './types';

// Segmentos = las 8 etapas REALES del Job. Indica en qué paso va, no un % inventado.
const props = defineProps<{
    catalog: StageInfo[];
    current: StageInfo | null;
    status: RunStatus;
}>();

const segments = computed(() =>
    props.catalog.map((stage) => {
        const pos = props.current?.position ?? 0;
        let state: 'done' | 'current' | 'halted' | 'pending' = 'pending';

        if (props.status === 'success') {
            state = 'done';
        } else if (stage.position < pos) {
            state = 'done';
        } else if (stage.position === pos) {
            state =
                props.status === 'blocked' || props.status === 'failed'
                    ? 'halted'
                    : 'current';
        }

        return { ...stage, state };
    }),
);
</script>

<template>
    <ol class="grid grid-cols-8 gap-1" aria-label="Etapas de la generación">
        <li
            v-for="seg in segments"
            :key="seg.key"
            class="min-w-0"
            :title="`${seg.position}. ${seg.label}`"
        >
            <span class="sr-only"
                >{{ seg.position }}. {{ seg.label }} —
                {{
                    {
                        done: 'completada',
                        current: 'en curso',
                        halted: 'detenida',
                        pending: 'pendiente',
                    }[seg.state]
                }}</span
            >
            <div
                class="h-1.5 rounded-full transition-colors"
                :class="{
                    'bg-primary': seg.state === 'done',
                    'animate-pulse bg-primary/60': seg.state === 'current',
                    'bg-destructive':
                        seg.state === 'halted' && status === 'failed',
                    'bg-amber-500':
                        seg.state === 'halted' && status === 'blocked',
                    'bg-muted': seg.state === 'pending',
                }"
                aria-hidden="true"
            />
            <p
                class="mt-1 hidden truncate text-[11px] leading-tight lg:block"
                :class="
                    seg.state === 'current' || seg.state === 'halted'
                        ? 'font-medium text-foreground'
                        : 'text-muted-foreground'
                "
                aria-hidden="true"
            >
                {{ seg.label }}
            </p>
        </li>
    </ol>
</template>
