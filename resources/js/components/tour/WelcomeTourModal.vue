<script setup lang="ts">
// Bienvenida al entrar por primera vez (Fase 2 de la auditoría, 15-oct-2026).
// Se muestra UNA sola vez por navegador (localStorage) — "explorar por mi
// cuenta" y "ver guía después" cuentan como vistas para no insistir.
import { Compass, Rocket } from 'lucide-vue-next'
import { onMounted, ref, watch } from 'vue'
import { useTour } from '@/composables/useTour'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'

const WELCOME_KEY = 'reportes_tour_welcome_seen_v1'
const open = ref(false)
const { startTour, hasSeenTour } = useTour()

onMounted(() => {
    if (!localStorage.getItem(WELCOME_KEY) && !hasSeenTour('dashboard')) {
        open.value = true
    }
})

function dismiss(): void {
    localStorage.setItem(WELCOME_KEY, '1')
    open.value = false
}

// Cerrar con Esc o clic afuera también cuenta como "vista" — nunca insiste.
watch(open, (v) => {
    if (!v) {
        localStorage.setItem(WELCOME_KEY, '1')
    }
})

function beginTour(): void {
    dismiss()
    startTour('dashboard')
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md">
            <DialogTitle class="flex items-center gap-2 text-base font-bold"><Rocket class="size-5 text-primary" /> Bienvenido a Reportes MR LANA</DialogTitle>
            <p class="text-sm text-muted-foreground">
                Te enseñamos a consultar información financiera, administrar colaboradores, crear objetivos y generar reportes — con un recorrido corto, directo sobre la pantalla.
            </p>
            <div class="mt-2 flex flex-col gap-2">
                <Button class="h-10 gap-2" @click="beginTour"><Compass class="size-4" /> Iniciar recorrido</Button>
                <Button variant="outline" class="h-10" @click="dismiss">Explorar por mi cuenta</Button>
                <button type="button" class="text-xs font-semibold text-muted-foreground hover:underline" @click="dismiss">Ver guía después</button>
            </div>
            <p class="text-[11px] text-muted-foreground">Puedes reiniciar este recorrido cuando quieras desde "Guía del sistema".</p>
        </DialogContent>
    </Dialog>
</template>
