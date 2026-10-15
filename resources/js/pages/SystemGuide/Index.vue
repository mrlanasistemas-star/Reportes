<script setup lang="ts">
// Centro de ayuda (rediseño 16-oct-2026): búsqueda, un módulo enfocado cuando
// se llega desde el botón flotante (?modulo=okr), tarjetas por módulo con su
// recorrido sobre la pantalla real y el manual ilustrado paso a paso
// (GuideManual.vue — mismo contenido validado de siempre).
import { Head, usePage } from '@inertiajs/vue3'
import { ArrowRight, BookOpen, Download, LifeBuoy, PlayCircle, Search, Sparkles, X } from 'lucide-vue-next'
import { computed, nextTick, onMounted, ref } from 'vue'
import GuideManual from '@/components/guide/GuideManual.vue'
import GuideModuleCard from '@/components/guide/GuideModuleCard.vue'
import { useTour } from '@/composables/useTour'
import AppLayout from '@/layouts/AppLayout.vue'
import { GUIDE_MODULES, moduleById } from '@/tour/modules'

defineOptions({ layout: AppLayout })

const page = usePage()
const { startTour } = useTour()
const search = ref('')
const manualFocus = ref<string | null>(null)

const focusedId = ref<string | null>(new URL(page.url, window.location.origin).searchParams.get('modulo'))
const focused = computed(() => moduleById(focusedId.value))

const visibleModules = computed(() => {
    const q = search.value.trim().toLowerCase()
    const list = GUIDE_MODULES.filter((m) => m.id !== focusedId.value)

    return q === '' ? list : list.filter((m) => [m.label, m.description, ...m.topics].join(' ').toLowerCase().includes(q))
})

function openManual(step: string): void {
    manualFocus.value = step
    nextTick(() => document.getElementById('guide-manual')?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

function clearFocus(): void {
    focusedId.value = null
    window.history.replaceState({}, '', '/guia-sistema')
}

onMounted(() => {
    if (focused.value?.manualStep) {
        manualFocus.value = focused.value.manualStep
    }
})
</script>

<template>
    <Head title="Guía del sistema" />

    <div class="mx-auto max-w-screen-2xl space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- Hero -->
        <section class="relative overflow-hidden rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-900/20 sm:p-10">
            <div class="pointer-events-none absolute -left-20 -top-24 size-72 rounded-full bg-indigo-600/40 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-28 right-0 size-80 rounded-full bg-fuchsia-600/30 blur-3xl" />
            <div class="relative flex flex-wrap items-end justify-between gap-6">
                <div class="max-w-2xl">
                    <p class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[11px] font-black uppercase tracking-[0.25em] text-indigo-200 ring-1 ring-white/15">
                        <LifeBuoy class="size-3.5" /> Centro de ayuda
                    </p>
                    <h1 class="mt-4 text-3xl font-black tracking-tight sm:text-4xl">¿Qué quieres aprender hoy?</h1>
                    <p class="mt-2 text-sm text-slate-300">Recorridos sobre la pantalla real de cada módulo y un manual ilustrado paso a paso.</p>
                    <div class="relative mt-5">
                        <Search class="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input v-model="search" type="search" placeholder="Busca un módulo o tema: OKR, línea base, comparativos…" class="h-12 w-full rounded-2xl border border-white/10 bg-white/10 pl-11 pr-4 text-sm text-white placeholder:text-slate-400 backdrop-blur transition focus:border-violet-300/60 focus:bg-white/15 focus:outline-none focus:ring-4 focus:ring-violet-500/20">
                    </div>
                </div>
                <a href="/guia-sistema/pdf" class="inline-flex h-11 items-center gap-2 rounded-2xl bg-emerald-500 px-5 text-sm font-black text-white transition duration-200 hover:-translate-y-0.5 hover:bg-emerald-400 hover:shadow-lg hover:shadow-emerald-500/30">
                    <Download class="size-4" /> Descargar guía en PDF
                </a>
            </div>
        </section>

        <!-- Módulo enfocado (desde el botón flotante) -->
        <Transition enter-active-class="transition duration-500 ease-out" enter-from-class="opacity-0 translate-y-2" appear>
            <section v-if="focused" class="relative overflow-hidden rounded-3xl border border-violet-300/40 bg-card p-6 shadow-xl shadow-violet-500/10 sm:p-8">
                <div class="pointer-events-none absolute -right-24 -top-24 size-80 rounded-full bg-gradient-to-br opacity-15 blur-3xl" :class="focused.tone" />
                <button type="button" class="absolute right-4 top-4 rounded-full p-1.5 text-muted-foreground transition hover:rotate-90 hover:bg-muted" aria-label="Ver todos los módulos" @click="clearFocus"><X class="size-4" /></button>
                <div class="relative flex flex-wrap items-center gap-5">
                    <span class="flex size-16 items-center justify-center rounded-3xl bg-gradient-to-br text-white shadow-xl" :class="focused.tone">
                        <component :is="focused.icon" class="size-8" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-1.5 text-[11px] font-black uppercase tracking-[0.2em] text-violet-600 dark:text-violet-300"><Sparkles class="size-3.5" /> Estás en este módulo</p>
                        <h2 class="mt-1 text-2xl font-black tracking-tight text-foreground">{{ focused.label }}</h2>
                        <p class="mt-1 text-sm text-muted-foreground">{{ focused.description }}</p>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span v-for="t in focused.topics" :key="t" class="rounded-full bg-violet-500/10 px-2.5 py-0.5 text-[11px] font-semibold text-violet-700 dark:text-violet-300">{{ t }}</span>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button v-if="focused.tourId" type="button" class="inline-flex h-12 items-center gap-2 rounded-2xl bg-gradient-to-r px-6 text-sm font-black text-white shadow-lg transition hover:-translate-y-0.5 hover:shadow-xl" :class="focused.tone" @click="startTour(focused.tourId)">
                            <PlayCircle class="size-5" /> Iniciar recorrido <ArrowRight class="size-4" />
                        </button>
                        <button v-if="focused.manualStep" type="button" class="inline-flex h-12 items-center gap-2 rounded-2xl border border-border px-5 text-sm font-bold text-foreground transition hover:-translate-y-0.5 hover:border-violet-300" @click="openManual(focused.manualStep)">
                            <BookOpen class="size-4" /> Ver en el manual
                        </button>
                    </div>
                </div>
            </section>
        </Transition>

        <!-- Módulos -->
        <section class="space-y-3">
            <div class="flex items-end justify-between gap-2">
                <h2 class="text-lg font-black tracking-tight text-foreground">{{ focused ? 'Otros módulos' : 'Módulos' }}</h2>
                <span class="text-xs text-muted-foreground">{{ visibleModules.length }} resultado(s)</span>
            </div>
            <TransitionGroup tag="div" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" enter-active-class="transition duration-300" enter-from-class="opacity-0 scale-95" leave-active-class="transition duration-150" leave-to-class="opacity-0 scale-95">
                <GuideModuleCard v-for="m in visibleModules" :key="m.id" :module="m" @tour="startTour" @manual="openManual" />
            </TransitionGroup>
            <p v-if="!visibleModules.length" class="rounded-3xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">Ningún módulo coincide con “{{ search }}”.</p>
        </section>

        <!-- Manual ilustrado -->
        <section class="space-y-3">
            <div class="flex items-center gap-2">
                <BookOpen class="size-5 text-violet-600" />
                <h2 class="text-lg font-black tracking-tight text-foreground">Manual paso a paso</h2>
            </div>
            <GuideManual :focus="manualFocus" />
        </section>
    </div>
</template>
