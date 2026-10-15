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
        <div class="relative overflow-hidden rounded-3xl border border-indigo-200/60 bg-gradient-to-br from-indigo-50 via-white to-violet-50 p-6 dark:border-indigo-500/20 dark:from-indigo-500/10 dark:via-card dark:to-violet-500/10">
            <div class="flex flex-wrap items-center gap-4">
                <span class="relative flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-white shadow-lg shadow-indigo-500/30">
                    <BarChart3 class="size-7" />
                    <Loader2 class="absolute -bottom-1 -right-1 size-5 animate-spin rounded-full bg-white p-0.5 text-indigo-600 dark:bg-card" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-base font-black text-slate-900 dark:text-slate-50">Construyendo el comparativo<template v-if="labelA && labelB"> {{ labelA }} vs {{ labelB }}</template></p>
                    <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ stage }}</p>
                </div>
                <span class="rounded-full bg-white/80 px-3 py-1 text-xs font-bold tabular-nums text-indigo-700 shadow-sm dark:bg-white/10 dark:text-indigo-200">{{ elapsed }} s</span>
            </div>
            <div class="mt-4 h-2 overflow-hidden rounded-full bg-indigo-100 dark:bg-white/10">
                <div class="h-full rounded-full bg-gradient-to-r from-indigo-500 to-violet-500 transition-all duration-1000 ease-out" :style="{ width: `${progress}%` }" />
            </div>
            <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">La primera consulta de cada periodo puede tardar unos segundos; después queda lista al instante. No cierres la ventana.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Skeleton v-for="i in 8" :key="i" class="h-32 rounded-2xl" />
        </div>
    </div>
</template>
