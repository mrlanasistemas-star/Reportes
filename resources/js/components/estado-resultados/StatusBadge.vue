<script setup lang="ts">
import {
    CheckCircle2,
    CircleDashed,
    AlertTriangle,
    UploadCloud,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { STATUS_META } from './types';
import type { SourceStatus } from './types';

const props = defineProps<{ status: SourceStatus }>();

const meta = computed(() => STATUS_META[props.status] ?? STATUS_META.pendiente);
const icon = computed(
    () =>
        ({
            procesado: CheckCircle2,
            cargado: UploadCloud,
            con_incidencias: AlertTriangle,
            pendiente: CircleDashed,
        })[props.status] ?? CircleDashed,
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap"
        :class="meta.cls"
    >
        <component :is="icon" class="size-3" />
        {{ meta.label }}
    </span>
</template>
