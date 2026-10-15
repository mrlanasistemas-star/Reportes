<script setup lang="ts">
// Botón flotante de ayuda (16-oct-2026): siempre a la derecha, con un pulso
// sutil. Estando en un módulo lleva al Centro de ayuda ENFOCADO en ese módulo
// (con su recorrido listo para iniciar); fuera de un módulo, al índice.
import { router, usePage } from '@inertiajs/vue3'
import { HelpCircle, Sparkles } from 'lucide-vue-next'
import { computed } from 'vue'
import { useTour } from '@/composables/useTour'
import { moduleForPath } from '@/tour/modules'

const page = usePage()
const { activeTourId } = useTour()

const pathname = computed(() => {
    try {
        return new URL(page.url, window.location.origin).pathname
    } catch {
        return window.location.pathname
    }
})
const current = computed(() => moduleForPath(pathname.value))
const hidden = computed(() => pathname.value.startsWith('/guia-sistema') || activeTourId.value !== null || !page.props.auth?.user)

function openGuide(): void {
    router.visit(current.value ? `/guia-sistema?modulo=${current.value.id}` : '/guia-sistema')
}
</script>

<template>
    <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-y-4 opacity-0" leave-active-class="transition duration-200" leave-to-class="translate-y-4 opacity-0">
        <button
            v-if="!hidden"
            type="button"
            class="group fixed bottom-24 right-4 z-40 flex items-center gap-2 rounded-full bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 p-1.5 pr-1.5 text-white shadow-xl shadow-violet-600/30 ring-1 ring-white/20 transition-all duration-300 hover:-translate-y-0.5 hover:pr-4 hover:shadow-2xl hover:shadow-violet-600/40 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-violet-400/50 md:bottom-6 md:right-6"
            :aria-label="current ? `Abrir la guía de ${current.label}` : 'Abrir la guía del sistema'"
            @click="openGuide"
        >
            <span class="relative flex size-11 items-center justify-center rounded-full bg-white/15 backdrop-blur">
                <span class="absolute inset-0 animate-ping rounded-full bg-white/25 [animation-duration:2.4s]" />
                <HelpCircle class="relative size-6 transition-transform duration-300 group-hover:rotate-12" />
                <Sparkles class="absolute -right-0.5 -top-0.5 size-3.5 text-amber-200 drop-shadow" />
            </span>
            <span class="max-w-0 overflow-hidden whitespace-nowrap text-sm font-bold opacity-0 transition-all duration-300 group-hover:max-w-[16rem] group-hover:opacity-100">
                {{ current ? `Guía de ${current.label}` : 'Guía del sistema' }}
            </span>
        </button>
    </Transition>
</template>
