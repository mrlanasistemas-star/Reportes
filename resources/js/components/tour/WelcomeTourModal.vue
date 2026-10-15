<script setup lang="ts">
// Bienvenida al recorrido. 16-oct-2026: se muestra UNA sola vez POR USUARIO
// (users.tour_welcome_seen_at) — antes solo se recordaba en localStorage y
// reaparecía en cada inicio de sesión desde otro navegador, ventana privada o
// la app de escritorio. Cualquier forma de cerrarla cuenta como vista.
import { usePage } from '@inertiajs/vue3'
import { BookOpen, Compass, Rocket } from 'lucide-vue-next'
import { computed, onMounted, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { useTour } from '@/composables/useTour'
import { okrRequest } from '@/lib/okrHttp'
import { welcomeTourFor } from '@/tour/modules'

const LEGACY_KEY = 'reportes_tour_welcome_seen_v1'
const open = ref(false)
const page = usePage<{ auth: { user: { tour_welcome_seen_at?: string | null } | null; modules?: string[] } }>()
// Fase 8.13: el recorrido empieza en el primer módulo que el rol SÍ puede abrir.
const welcomeTour = computed(() => welcomeTourFor(page.props.auth?.modules))
const seenOnServer = computed(() => !!page.props.auth?.user?.tour_welcome_seen_at)
const { startTour } = useTour()
let persisted = false

function legacySeen(): boolean {
    try {
        return localStorage.getItem(LEGACY_KEY) === '1'
    } catch {
        return false
    }
}

function persist(): void {
    if (persisted || seenOnServer.value) {
        return
    }

    persisted = true

    try {
        localStorage.setItem(LEGACY_KEY, '1')
    } catch {
        // almacenamiento bloqueado: el servidor es la fuente de verdad
    }

    void okrRequest('/guia-sistema/bienvenida-vista', { method: 'POST' })
}

onMounted(() => {
    if (!page.props.auth?.user || seenOnServer.value) {
        return
    }

    // Quien ya la cerró en este navegador antes del cambio no la vuelve a ver.
    if (legacySeen()) {
        persist()

        return
    }

    open.value = true
})

watch(open, (v) => {
    if (!v) {
        persist()
    }
})

function dismiss(): void {
    open.value = false
}

function beginTour(): void {
    open.value = false

    if (welcomeTour.value) {
        startTour(welcomeTour.value)
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md overflow-hidden p-0">
            <div class="relative border-b bg-accent/60 px-6 pb-6 pt-7">
                <span class="flex size-12 items-center justify-center rounded-xl border bg-card text-primary shadow-soft-xs"><Rocket class="size-6" /></span>
                <DialogTitle class="mt-4 text-xl font-bold tracking-tight text-foreground">Bienvenido a Reportes MR LANA</DialogTitle>
                <p class="mt-1 text-sm text-muted-foreground">Un recorrido corto sobre la pantalla real de los módulos que tienes habilitados. Puedes salir en cualquier momento.</p>
            </div>
            <div class="space-y-2 p-6">
                <Button v-if="welcomeTour" class="h-11 w-full gap-2 rounded-xl" @click="beginTour"><Compass class="size-4" /> Iniciar recorrido</Button>
                <Button variant="outline" class="h-11 w-full rounded-xl" @click="dismiss">Explorar por mi cuenta</Button>
                <p class="flex items-center justify-center gap-1.5 pt-1 text-[11px] text-muted-foreground">
                    <BookOpen class="size-3.5 shrink-0" /> Solo se muestra una vez. Puedes repetirlo desde «Guía del sistema» o el botón de ayuda.
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
