<script setup lang="ts">
// Tarjeta de módulo del Centro de ayuda (16-oct-2026).
import { BookOpen, PlayCircle } from 'lucide-vue-next'
import type { GuideModule } from '@/tour/modules'

defineProps<{ module: GuideModule; highlighted?: boolean }>()
const emit = defineEmits<{ tour: [id: string]; manual: [step: string] }>()
</script>

<template>
    <article
        class="group relative flex flex-col overflow-hidden rounded-3xl border bg-card p-5 transition-all duration-300 hover:-translate-y-1 hover:shadow-xl"
        :class="highlighted ? 'border-violet-400/60 shadow-lg shadow-violet-500/15 ring-2 ring-violet-400/40' : 'border-border hover:border-violet-300/50'"
    >
        <div class="pointer-events-none absolute -right-12 -top-12 size-32 rounded-full bg-gradient-to-br opacity-10 blur-2xl transition-opacity duration-300 group-hover:opacity-25" :class="module.tone" />
        <div class="flex items-start gap-3">
            <span class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br text-white shadow-lg transition-transform duration-300 group-hover:scale-110 group-hover:rotate-3" :class="module.tone">
                <component :is="module.icon" class="size-6" />
            </span>
            <div class="min-w-0">
                <h3 class="text-base font-black tracking-tight text-foreground">{{ module.label }}</h3>
                <p class="mt-0.5 text-xs leading-5 text-muted-foreground">{{ module.description }}</p>
            </div>
        </div>
        <div class="mt-3 flex flex-wrap gap-1.5">
            <span v-for="t in module.topics" :key="t" class="rounded-full bg-muted px-2 py-0.5 text-[10px] font-semibold text-muted-foreground">{{ t }}</span>
        </div>
        <div class="mt-auto flex flex-wrap gap-2 pt-4">
            <button
                v-if="module.tourId" type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-xl bg-gradient-to-r px-3.5 text-xs font-bold text-white shadow-md transition hover:-translate-y-0.5 hover:shadow-lg"
                :class="module.tone" @click="emit('tour', module.tourId)"
            >
                <PlayCircle class="size-4" /> Recorrido en pantalla
            </button>
            <button
                v-if="module.manualStep" type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-border px-3.5 text-xs font-bold text-foreground transition hover:-translate-y-0.5 hover:border-violet-300 hover:text-violet-700 dark:hover:text-violet-300"
                @click="emit('manual', module.manualStep)"
            >
                <BookOpen class="size-4" /> Ver en el manual
            </button>
        </div>
    </article>
</template>
