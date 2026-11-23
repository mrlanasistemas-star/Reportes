<script setup lang="ts">
/**
 * SwitchField — interruptor con etiqueta y descripción dentro de una tarjeta.
 *
 * Componente genérico; actualmente no se importa en ninguna vista.
 * El Switch de reka-ui usa `modelValue` (no `checked`): por eso se enlaza con `v-model`.
 *
 * Props:
 * - label: texto principal (opcional).
 * - description: texto de ayuda (opcional).
 * - disabled: deshabilita el interruptor.
 *
 * v-model: valor booleano del interruptor.
 */
import { Switch } from '@/components/ui/switch';

const model = defineModel<boolean>({ default: false });

withDefaults(
    defineProps<{
        label?: string;
        description?: string | null;
        disabled?: boolean;
    }>(),
    {
        disabled: false,
    },
);
</script>

<template>
    <div
        class="flex items-start justify-between gap-4 rounded-3xl border border-border bg-card p-4"
    >
        <div class="space-y-1">
            <p v-if="label" class="text-sm font-semibold text-foreground">
                {{ label }}
            </p>

            <p v-if="description" class="text-xs text-muted-foreground">
                {{ description }}
            </p>
        </div>

        <Switch v-model="model" :disabled="disabled" />
    </div>
</template>
