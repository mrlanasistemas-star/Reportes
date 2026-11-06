<script setup lang="ts">
import { ArrowRight, FolderInput } from 'lucide-vue-next';
import { computed } from 'vue';
import type { SourceChecklist } from './types';

/** Aviso bajo la barra superior: cuántas fuentes faltan y un acceso directo a Fuentes. */
const props = defineProps<{ checklist: SourceChecklist }>();
defineEmits<{ go: [] }>();

const pending = computed(() =>
    props.checklist.items.filter((i) => i.state !== 'lista'),
);
const required = computed(() => pending.value.filter((i) => i.required));
const names = computed(
    () =>
        pending.value
            .slice(0, 3)
            .map((i) => i.label.replace(/^Estado de cuenta: /, ''))
            .join(', ') + (pending.value.length > 3 ? '…' : ''),
);
</script>

<template>
    <div
        v-if="pending.length"
        role="status"
        class="flex flex-col gap-2 rounded-2xl border px-4 py-3 text-sm sm:flex-row sm:items-center"
        :class="
            required.length
                ? 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100'
                : 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100'
        "
    >
        <FolderInput class="size-4.5 shrink-0" aria-hidden="true" />
        <p class="min-w-0 flex-1">
            <b>{{
                pending.length === 1
                    ? 'Falta 1 fuente'
                    : `Faltan ${pending.length} fuentes`
            }}</b>
            <span v-if="required.length !== pending.length">
                ({{ required.length }} obligatoria{{
                    required.length === 1 ? '' : 's'
                }})</span
            >: {{ names }}
        </p>
        <button
            type="button"
            class="inline-flex shrink-0 items-center gap-1 self-start rounded-lg bg-foreground px-3 py-1.5 text-xs font-bold text-background transition hover:opacity-90 focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:outline-none sm:self-auto"
            @click="$emit('go')"
        >
            Ver qué falta <ArrowRight class="size-3.5" />
        </button>
    </div>
</template>
