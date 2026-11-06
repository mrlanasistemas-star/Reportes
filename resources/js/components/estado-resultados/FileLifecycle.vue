<script setup lang="ts">
import { Check, Copy, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

/** Ciclo de vida de un estado de cuenta: recibido → validado → procesado → conciliado. */
const props = defineProps<{
    lifecycle: string | null | undefined;
    label?: string | null;
    compact?: boolean;
}>();

const STEPS = [
    { key: 'recibido', label: 'Recibido' },
    { key: 'validado', label: 'Validado' },
    { key: 'procesado', label: 'Procesado' },
    { key: 'conciliado', label: 'Conciliado' },
];

const index = computed(() => STEPS.findIndex((s) => s.key === props.lifecycle));
const terminal = computed(() =>
    props.lifecycle === 'rechazado'
        ? { label: 'Rechazado', cls: 'bg-rose-600 text-white', icon: XCircle }
        : props.lifecycle === 'duplicado'
          ? {
                label: props.label ?? 'Ya estaba cargado',
                cls: 'bg-slate-500 text-white',
                icon: Copy,
            }
          : props.lifecycle === 'reemplazado'
            ? {
                  label: 'Reemplazado',
                  cls: 'bg-slate-400 text-white',
                  icon: Copy,
              }
            : null,
);
</script>

<template>
    <span
        v-if="terminal"
        class="inline-flex items-center gap-1 rounded-full px-2 py-px text-[10.5px] font-bold"
        :class="terminal.cls"
        ><component :is="terminal.icon" class="size-3" />
        {{ terminal.label }}</span
    >
    <ol
        v-else-if="index >= 0"
        class="inline-flex items-center gap-1"
        :aria-label="`Ciclo del archivo: ${STEPS[index].label}`"
    >
        <li
            v-for="(s, i) in STEPS"
            :key="s.key"
            class="inline-flex items-center gap-1"
        >
            <span
                class="inline-flex items-center gap-0.5 rounded-full px-1.5 py-px text-[10px] font-bold"
                :class="
                    i < index
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                        : i === index
                          ? 'bg-emerald-600 text-white dark:bg-emerald-500 dark:text-emerald-950'
                          : 'bg-muted text-muted-foreground'
                "
                :title="s.label"
            >
                <Check v-if="i <= index" class="size-2.5" stroke-width="3" />
                <span :class="compact && i !== index ? 'sr-only' : ''">{{
                    s.label
                }}</span>
            </span>
            <span
                v-if="i < STEPS.length - 1"
                class="h-px w-2 bg-border"
                aria-hidden="true"
            />
        </li>
    </ol>
</template>
