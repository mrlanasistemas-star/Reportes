<script setup lang="ts">
// Pantalla de carga del comparativo (16-oct-2026): la primera consulta de un
// periodo arma su radiografía completa (~10-20 s). Antes solo se veían
// esqueletos sin explicación; ahora hay mensaje, etapas y contador, y la carga
// espera lo necesario (sin tiempo límite artificial).
import { BarChart3, Loader2 } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Skeleton } from '@/components/ui/skeleton'

defineProps<{ labelA?: string | null; labelB?: string | null }>()

const elapsed = ref(0)
let timer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
    timer = setInterval(() => (elapsed.value += 1), 1000)
})
onBeforeUnmount(() => clearInterval(timer))

const stage = computed(() => {
    if (elapsed.value < 4) {
        return 'Leyendo la radiografía del primer periodo…'
    }

    if (elapsed.value < 9) {
        return 'Leyendo la radiografía del segundo periodo…'
    }

    if (elapsed.value < 15) {
        return 'Calculando variaciones y gráficas…'
    }

    return 'Casi listo — los periodos con mucha información tardan un poco más.'
})
const progress = computed(() => Math.min(95, Math.round(100 * (1 - Math.exp(-elapsed.value / 7)))))
</script>

<template>
    <div class="space-y-4">
        <div class="relative overflow-hidden rounded-2xl border border-primary/30 bg-accent/40 p-6">
            <div class="flex flex-wrap items-center gap-4">
                <span class="relative flex size-14 items-center justify-center rounded-2xl bg-primary text-primary-foreground">
                    <BarChart3 class="size-7" />
                    <Loader2 class="absolute -bottom-1 -right-1 size-5 animate-spin rounded-full bg-card p-0.5 text-primary" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-base font-semibold text-foreground">Construyendo el comparativo<template v-if="labelA && labelB"> {{ labelA }} vs {{ labelB }}</template></p>
                    <p class="mt-0.5 text-sm text-muted-foreground">{{ stage }}</p>
                </div>
                <span class="rounded-full bg-card/80 px-3 py-1 text-xs font-bold tabular-nums text-primary shadow-sm">{{ elapsed }} s</span>
            </div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-accent">
                <div class="h-full rounded-full bg-primary transition-all duration-1000 ease-out" :style="{ width: `${progress}%` }" />
            </div>
            <p class="mt-2 text-[11px] text-muted-foreground">La primera consulta de cada periodo puede tardar unos segundos; después queda lista al instante. No cierres la ventana.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Skeleton v-for="i in 8" :key="i" class="h-32 rounded-2xl" />
        </div>
    </div>
</template>
