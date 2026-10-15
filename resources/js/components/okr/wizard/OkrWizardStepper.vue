<script setup lang="ts">
// Stepper del asistente "Asignar OKR" — barra de progreso animada, paso actual
// con halo pulsante y pasos completados clicables para volver atrás.
import { Check } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{ steps: string[]; current: number }>()
const emit = defineEmits<{ go: [step: number] }>()

const progress = computed(() => (props.steps.length <= 1 ? 100 : ((props.current - 1) / (props.steps.length - 1)) * 100))
</script>

<template>
    <div class="relative px-6 pb-3 pt-4">
        <div class="absolute left-[calc(1.5rem+14px)] right-[calc(1.5rem+14px)] top-[30px] h-1 rounded-full bg-muted">
            <div class="h-full rounded-full bg-primary transition-all duration-700 ease-out" :style="{ width: `${progress}%` }" />
        </div>
        <ol class="relative flex items-start justify-between">
            <li v-for="(label, i) in steps" :key="label" class="flex w-24 flex-col items-center gap-1.5 text-center sm:w-36">
                <button
                    type="button"
                    class="group relative flex size-7 items-center justify-center rounded-full text-[11px] font-bold transition-all duration-300"
                    :class="[
                        i + 1 < current ? 'cursor-pointer bg-primary/15 text-primary ring-1 ring-primary/30 hover:bg-primary/25' : '',
                        i + 1 === current ? 'bg-primary text-primary-foreground shadow-soft-xs ring-4 ring-primary/15' : '',
                        i + 1 > current ? 'cursor-default bg-background text-muted-foreground ring-1 ring-border' : '',
                    ]"
                    :disabled="i + 1 >= current"
                    :aria-label="`Ir al paso ${i + 1}: ${label}`"
                    @click="emit('go', i + 1)"
                >
                    <Check v-if="i + 1 < current" class="size-3.5" />
                    <span v-else class="relative">{{ i + 1 }}</span>
                </button>
                <span class="hidden text-[11px] font-semibold leading-tight sm:block" :class="i + 1 <= current ? 'text-foreground' : 'text-muted-foreground'">{{ label }}</span>
            </li>
        </ol>
    </div>
</template>
