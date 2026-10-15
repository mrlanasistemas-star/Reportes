<script setup lang="ts">
import { Check, CircleAlert, Lock, Loader2 } from 'lucide-vue-next'

// Fase 8.6 (15-oct-2026): stepper compacto. Cada etapa muestra número/estado en
// el círculo y un texto corto debajo (antes un badge que se cortaba en la
// última columna). En móvil se desplaza horizontalmente sin desbordar la página.
// Una etapa bloqueada explica por qué no se puede abrir.
type Step = { key: string; label: string; description: string; status: 'pending' | 'ready' | 'blocked' | 'running' | 'completed' | 'generated' | 'error' }

defineProps<{ steps: Step[]; current: string }>()
const emit = defineEmits<{ (event: 'select', key: string): void }>()

const STATUS_TEXT: Record<Step['status'], string> = {
    pending: 'Pendiente',
    ready: 'Lista',
    blocked: 'Bloqueada',
    running: 'En proceso',
    completed: 'Completa',
    generated: 'Generado',
    error: 'Con error',
}
</script>

<template>
    <nav aria-label="Etapas del flujo" class="rounded-2xl border bg-card p-2 shadow-soft-xs" data-tour="carga-stepper">
        <ol class="scrollbar-none flex gap-1 overflow-x-auto">
            <li v-for="(step, index) in steps" :key="step.key" class="min-w-[8.5rem] flex-1">
                <button
                    type="button"
                    class="group flex w-full items-start gap-2.5 rounded-xl px-3 py-2.5 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="[
                        current === step.key ? 'bg-accent' : 'hover:bg-muted/60',
                        step.status === 'blocked' ? 'cursor-not-allowed' : '',
                    ]"
                    :disabled="step.status === 'blocked'"
                    :aria-current="current === step.key ? 'step' : undefined"
                    :title="step.status === 'blocked' ? `Bloqueada: completa las etapas anteriores. ${step.description}` : step.description"
                    @click="emit('select', step.key)"
                >
                    <span
                        class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-bold"
                        :class="{
                            'border-primary bg-primary text-primary-foreground': step.status === 'completed' || step.status === 'generated' || current === step.key,
                            'border-info-border bg-info-soft text-info': step.status === 'running' && current !== step.key,
                            'border-danger-border bg-danger-soft text-danger': step.status === 'error' && current !== step.key,
                            'border-border bg-muted text-muted-foreground': ['pending', 'ready', 'blocked'].includes(step.status) && current !== step.key,
                        }"
                    >
                        <Check v-if="step.status === 'completed' || step.status === 'generated'" class="size-3.5" />
                        <Loader2 v-else-if="step.status === 'running'" class="size-3.5 animate-spin" />
                        <CircleAlert v-else-if="step.status === 'error'" class="size-3.5" />
                        <Lock v-else-if="step.status === 'blocked'" class="size-3.5" />
                        <span v-else>{{ index + 1 }}</span>
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm leading-tight font-semibold" :class="step.status === 'blocked' ? 'text-muted-foreground' : 'text-foreground'">{{ step.label }}</span>
                        <span
                            class="mt-0.5 block text-[11px] font-medium"
                            :class="{
                                'text-success': step.status === 'completed' || step.status === 'generated',
                                'text-info': step.status === 'running' || step.status === 'ready',
                                'text-danger': step.status === 'error',
                                'text-muted-foreground': step.status === 'pending' || step.status === 'blocked',
                            }"
                        >{{ STATUS_TEXT[step.status] ?? step.status }}</span>
                    </span>
                </button>
            </li>
        </ol>
    </nav>
</template>
