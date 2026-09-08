<script setup lang="ts">
// Stepper moderno del wizard (sección T del pedido): círculo + línea entre
// pasos + estado (completo/actual/pendiente). Compacto en mobile.
import { Check } from 'lucide-vue-next'

defineProps<{ steps: string[]; current: number }>()
</script>

<template>
    <ol class="flex items-center gap-1 overflow-x-auto pb-1 scrollbar-none sm:gap-2">
        <template v-for="(label, i) in steps" :key="label">
            <li class="flex shrink-0 items-center gap-2">
                <span
                    class="flex size-8 items-center justify-center rounded-full text-xs font-bold transition-all duration-300"
                    :class="i + 1 < current
                        ? 'bg-primary text-primary-foreground'
                        : i + 1 === current
                            ? 'bg-primary text-primary-foreground ring-4 ring-primary/15'
                            : 'bg-muted text-muted-foreground'"
                >
                    <Check v-if="i + 1 < current" class="size-4" />
                    <span v-else>{{ i + 1 }}</span>
                </span>
                <span
                    class="hidden text-xs font-semibold whitespace-nowrap sm:inline"
                    :class="i + 1 <= current ? 'text-foreground' : 'text-muted-foreground'"
                >
                    {{ label }}
                </span>
            </li>
            <li v-if="i < steps.length - 1" class="h-px w-4 shrink-0 transition-colors duration-300 sm:w-10" :class="i + 1 < current ? 'bg-primary' : 'bg-border'" />
        </template>
    </ol>
</template>
