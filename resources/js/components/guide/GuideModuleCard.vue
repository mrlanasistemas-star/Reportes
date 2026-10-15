<script setup lang="ts">
// Tarjeta de módulo del Centro de ayuda. Fase 8.13 (15-oct-2026): tarjeta
// sobria (icono en placa neutra, botón primario único) en lugar de un color
// fluorescente distinto por módulo.
import { BookOpen, PlayCircle } from 'lucide-vue-next'
import type { GuideModule } from '@/tour/modules'
import GuideModuleFacts from './GuideModuleFacts.vue'

defineProps<{ module: GuideModule; highlighted?: boolean }>()
const emit = defineEmits<{ tour: [id: string]; manual: [step: string] }>()
</script>

<template>
    <article
        class="flex flex-col rounded-2xl border bg-card p-5 shadow-soft-xs transition-shadow hover:shadow-soft-md"
        :class="highlighted ? 'border-primary ring-2 ring-primary/20' : 'border-border'"
    >
        <div class="flex items-start gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-accent text-primary">
                <component :is="module.icon" class="size-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <h3 class="text-base font-semibold tracking-tight text-foreground">{{ module.label }}</h3>
                <p class="mt-0.5 text-sm leading-relaxed text-muted-foreground">{{ module.description }}</p>
            </div>
        </div>
        <GuideModuleFacts class="mt-3" :module="module" />
        <div class="mt-3 flex flex-wrap gap-1.5">
            <span v-for="t in module.topics" :key="t" class="app-pill app-pill-muted">{{ t }}</span>
        </div>
        <div class="mt-auto flex flex-wrap gap-2 pt-4">
            <button
                v-if="module.tourId" type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3.5 text-sm font-semibold text-primary-foreground transition hover:bg-primary/90"
                @click="emit('tour', module.tourId)"
            >
                <PlayCircle class="size-4" aria-hidden="true" /> Recorrido en pantalla
            </button>
            <button
                v-if="module.manualStep" type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border bg-card px-3.5 text-sm font-semibold text-foreground transition hover:bg-muted"
                @click="emit('manual', module.manualStep)"
            >
                <BookOpen class="size-4" aria-hidden="true" /> Ver en el manual
            </button>
        </div>
    </article>
</template>
