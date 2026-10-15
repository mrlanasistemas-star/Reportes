<script setup lang="ts">
// Botón flotante de ayuda (16-oct-2026): siempre a la derecha, con un pulso
// sutil. Estando en un módulo lleva al Centro de ayuda ENFOCADO en ese módulo
// (con su recorrido listo para iniciar); fuera de un módulo, al índice.
import { router, usePage } from '@inertiajs/vue3'
import { HelpCircle } from 'lucide-vue-next'
import { computed } from 'vue'
import { useTour } from '@/composables/useTour'
import { guideModulesFor, moduleForPath } from '@/tour/modules'

const page = usePage()
const { activeTourId } = useTour()

const pathname = computed(() => {
    try {
        return new URL(page.url, window.location.origin).pathname
    } catch {
        return window.location.pathname
    }
})
// Solo se enfoca un módulo cuyo uso el rol tiene permitido (Fase 8.13).
const current = computed(() => {
    const found = moduleForPath(pathname.value)

    return found && guideModulesFor(page.props.auth?.modules).some((m) => m.id === found.id) ? found : null
})
const hidden = computed(() => pathname.value.startsWith('/guia-sistema') || activeTourId.value !== null || !page.props.auth?.user)

function openGuide(): void {
    // «desde»: el recorrido del módulo vuelve a la pantalla exacta de la que vienes
    // (p. ej. el detalle de un OKR) en vez de a la portada del módulo.
    router.visit(current.value ? `/guia-sistema?modulo=${current.value.id}&desde=${encodeURIComponent(pathname.value)}` : '/guia-sistema')
}
</script>

<template>
    <Transition enter-active-class="transition duration-300 ease-out" enter-from-class="translate-y-4 opacity-0" leave-active-class="transition duration-200" leave-to-class="translate-y-4 opacity-0">
        <button
            v-if="!hidden"
            type="button"
            class="group fixed bottom-24 right-4 z-40 flex items-center gap-1 rounded-full border border-primary/30 bg-primary p-1 text-primary-foreground shadow-soft-lg transition-all duration-300 hover:pr-4 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary/30 md:bottom-6 md:right-6"
            :aria-label="current ? `Abrir la guía de ${current.label}` : 'Abrir la guía del sistema'"
            @click="openGuide"
        >
            <!-- Fase 8.13: sin pulso infinito ni degradado: visible pero sin distraer. -->
            <span class="relative flex size-10 items-center justify-center rounded-full">
                <HelpCircle class="size-5" aria-hidden="true" />
            </span>
            <span class="max-w-0 overflow-hidden whitespace-nowrap text-sm font-bold opacity-0 transition-all duration-300 group-hover:max-w-[16rem] group-hover:opacity-100 group-focus-visible:max-w-[16rem] group-focus-visible:opacity-100">
                {{ current ? `Guía de ${current.label}` : 'Guía del sistema' }}
            </span>
        </button>
    </Transition>
</template>
