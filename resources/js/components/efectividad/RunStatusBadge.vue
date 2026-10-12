<script setup lang="ts">
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Clock,
    History,
    Loader2,
    RotateCw,
    XCircle,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import { STATE_META } from './format';
import type { DisplayState } from './types';

const props = defineProps<{
    state: DisplayState;
    label?: string;
    class?: string;
}>();

const meta = computed(() => STATE_META[props.state] ?? STATE_META.queued);

const icon = computed(
    () =>
        ({
            queued: Clock,
            running: Loader2,
            retrying: RotateCw,
            delayed: AlertTriangle,
            stalled: AlertTriangle,
            blocked: Ban,
            failed: XCircle,
            success: CheckCircle2,
            obsolete: History,
        })[props.state] ?? Clock,
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                meta.tone,
                props.class,
            )
        "
        role="status"
    >
        <component
            :is="icon"
            class="size-3.5"
            :class="{ 'animate-spin': state === 'running' }"
            aria-hidden="true"
        />
        {{ label ?? meta.label }}
    </span>
</template>
