<script setup lang="ts">
/** CategoryBadge — etiqueta de categoría EBITDA (Diamante, Master, Senior, Junior, Mantenido). */
import { computed } from 'vue';
import { categoryMeta } from '@/lib/hardData';

const props = withDefaults(
    defineProps<{ category: string | null | undefined; size?: 'sm' | 'md' }>(),
    { size: 'sm' },
);
const meta = computed(() => categoryMeta(props.category));
</script>

<template>
    <span
        :class="[
            'inline-flex shrink-0 items-center gap-1.5 rounded-full font-semibold ring-1 ring-inset',
            size === 'sm' ? 'px-2 py-0.5 text-[11px]' : 'px-2.5 py-1 text-xs',
            meta.chip,
        ]"
        :title="meta.min"
    >
        <span :class="['size-1.5 rounded-full', meta.dot]" aria-hidden="true" />
        {{ meta.label }}
    </span>
</template>
