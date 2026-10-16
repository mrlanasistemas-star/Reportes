<script setup lang="ts">
// Botón flotante de ayuda (16-oct-2026): siempre a la derecha, con un halo
// animado sutil. Ya NO te saca del módulo: abre un pequeño menú para elegir
// entre "hacer el recorrido de este módulo" (aquí mismo) o "ir a la guía
// completa". El módulo enfocado y el recorrido respetan permisos de rol.
import { router, usePage } from '@inertiajs/vue3'
import { BookOpen, HelpCircle, Route } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useTour } from '@/composables/useTour'
import { guideModulesFor, moduleForPath } from '@/tour/modules'

const page = usePage()
const { activeTourId, startTour } = useTour()
const open = ref(false)

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

// Recorrido del módulo actual, SIN salir de la pantalla en la que estás
// («desde» hace que el recorrido vuelva aquí al terminar).
function startModuleTour(): void {
    open.value = false

    if (current.value?.tourId) {
        startTour(current.value.tourId, pathname.value)
    }
}

function openFullGuide(): void {
    open.value = false
    router.visit(current.value ? `/guia-sistema?modulo=${current.value.id}&desde=${encodeURIComponent(pathname.value)}` : '/guia-sistema')
}
</script>

<template>
    <Popover v-if="!hidden" v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="group fixed bottom-24 right-4 z-40 flex items-center gap-1 rounded-full border border-primary/30 bg-primary p-1 text-primary-foreground shadow-soft-lg transition-all duration-300 hover:pr-4 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-primary/30 md:bottom-6 md:right-6"
                :aria-label="current ? `Ayuda de ${current.label}` : 'Ayuda del sistema'"
            >
                <!-- Halo animado (respeta prefers-reduced-motion): visible sin distraer. -->
                <span class="pointer-events-none absolute inset-0 rounded-full bg-primary/40 motion-safe:animate-ping" aria-hidden="true" />
                <span class="relative flex size-10 items-center justify-center rounded-full">
                    <HelpCircle class="size-5 transition-transform duration-300 group-hover:rotate-12" aria-hidden="true" />
                </span>
                <span class="relative max-w-0 overflow-hidden whitespace-nowrap text-sm font-bold opacity-0 transition-all duration-300 group-hover:max-w-[16rem] group-hover:opacity-100 group-focus-visible:max-w-[16rem] group-focus-visible:opacity-100">
                    {{ current ? `Ayuda de ${current.label}` : 'Ayuda' }}
                </span>
            </button>
        </PopoverTrigger>

        <PopoverContent align="end" side="top" :side-offset="12" class="w-72 p-2">
            <div class="px-2 py-1.5">
                <p class="text-sm font-semibold text-foreground">
                    {{ current ? `Ayuda — ${current.label}` : 'Ayuda del sistema' }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ current ? '¿Qué necesitas en este módulo?' : 'Abre la guía del sistema.' }}
                </p>
            </div>

            <div class="mt-1 flex flex-col gap-1">
                <button
                    v-if="current?.tourId"
                    type="button"
                    class="flex items-start gap-2.5 rounded-lg px-2 py-2 text-left text-sm transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                    @click="startModuleTour"
                >
                    <Route class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                    <span>
                        <span class="block font-medium text-foreground">Hacer el recorrido de este módulo</span>
                        <span class="block text-xs text-muted-foreground">Te guío paso a paso aquí mismo, sin salir de la pantalla.</span>
                    </span>
                </button>

                <button
                    type="button"
                    class="flex items-start gap-2.5 rounded-lg px-2 py-2 text-left text-sm transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                    @click="openFullGuide"
                >
                    <BookOpen class="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
                    <span>
                        <span class="block font-medium text-foreground">Ir a la guía completa</span>
                        <span class="block text-xs text-muted-foreground">Abre el Centro de ayuda con los módulos de tu rol.</span>
                    </span>
                </button>
            </div>
        </PopoverContent>
    </Popover>
</template>
