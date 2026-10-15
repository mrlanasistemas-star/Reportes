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

const LEGACY_KEY = 'reportes_tour_welcome_seen_v1'
const open = ref(false)
const page = usePage<{ auth: { user: { tour_welcome_seen_at?: string | null } | null } }>()
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
    startTour('dashboard')
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md overflow-hidden p-0">
            <div class="relative bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 px-6 pb-6 pt-7 text-white">
                <div class="pointer-events-none absolute -right-10 -top-10 size-40 rounded-full bg-white/10 blur-2xl" />
                <span class="flex size-12 items-center justify-center rounded-2xl bg-white/15 ring-1 ring-white/30 backdrop-blur"><Rocket class="size-6" /></span>
                <DialogTitle class="mt-4 text-xl font-black tracking-tight">Bienvenido a Reportes MR LANA</DialogTitle>
                <p class="mt-1 text-sm text-white/85">Un recorrido corto sobre la pantalla real: información financiera, colaboradores, OKR y reportes.</p>
            </div>
            <div class="space-y-2 p-6">
                <Button class="h-11 w-full gap-2 rounded-xl" @click="beginTour"><Compass class="size-4" /> Iniciar recorrido</Button>
                <Button variant="outline" class="h-11 w-full rounded-xl" @click="dismiss">Explorar por mi cuenta</Button>
                <p class="flex items-center justify-center gap-1.5 pt-1 text-[11px] text-muted-foreground">
                    <BookOpen class="size-3.5" /> Solo se muestra una vez. La guía siempre está en el botón flotante de la derecha.
                </p>
            </div>
        </DialogContent>
    </Dialog>
</template>
