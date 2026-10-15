<script setup lang="ts">
// Overlay del recorrido interactivo (Fase 2 de la auditoría, 15-oct-2026).
// Técnica de "spotlight" sin dependencias externas: un anillo posicionado
// exactamente sobre el elemento objetivo con box-shadow enorme simula el
// atenuado del resto de la pantalla sin bloquear clics fuera de la tarjeta —
// "no bloquear la navegación normal fuera del tour" (requisito explícito).
import { ArrowLeft, ArrowRight, Lightbulb, X } from 'lucide-vue-next'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useTour } from '@/composables/useTour'

const { currentStep, stepIndex, totalSteps, isFirstStep, isLastStep, activeTourId, pendingNavigation, lastDirection, next, prev, skip } = useTour()

const targetRect = ref<{ top: number; left: number; width: number; height: number } | null>(null)
const searching = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | null = null

function locateTarget(): void {
    clearTimeout(searchTimer ?? undefined)

    if (pendingNavigation.value) {
        searchTimer = setTimeout(locateTarget, 150)

        return
    }

    const step = currentStep.value

    if (!step) {
        targetRect.value = null

        return
    }

    if (!step.target) {
        targetRect.value = null // paso introductorio centrado, sin resaltar nada

        return
    }

    const el = document.querySelector(`[data-tour="${step.target}"]`)

    if (!el) {
        // Salto automático (requisito explícito): el elemento no existe en esta
        // pantalla/rol — nunca se traba el recorrido esperando algo que no va a aparecer.
        if (!searching.value) {
            searching.value = true
            targetRect.value = null // no se muestra una tarjeta «fantasma» mientras se busca
            searchTimer = setTimeout(() => {
                searching.value = false

                if (currentStep.value !== step) {
                    locateTarget()
                } else if (!document.querySelector(`[data-tour="${step.target}"]`)) {
                    // Al retroceder se salta hacia atrás (antes siempre avanzaba y
                    // «Anterior» regresaba al mismo paso).
                    if (lastDirection.value < 0 && !isFirstStep.value) {
                        prev()
                    } else {
                        next()
                    }
                } else {
                    locateTarget()
                }
            }, 900)
        }

        return
    }

    searching.value = false
    el.scrollIntoView({ behavior: 'smooth', block: 'center' })
    const rect = el.getBoundingClientRect()
    targetRect.value = { top: rect.top, left: rect.left, width: rect.width, height: rect.height }
}

watch([currentStep, pendingNavigation], () => nextTick(locateTarget), { immediate: true })

function onScrollOrResize() {
    if (currentStep.value?.target) {
        locateTarget()
    }
}

// Fase 8.15: Escape cierra el recorrido (la guía nunca debe atrapar al usuario).
function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && activeTourId.value) {
        skip()
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown)
    window.addEventListener('resize', onScrollOrResize)
    window.addEventListener('scroll', onScrollOrResize, true)
})
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown)
    window.removeEventListener('resize', onScrollOrResize)
    window.removeEventListener('scroll', onScrollOrResize, true)
    clearTimeout(searchTimer ?? undefined)
})

const ringStyle = computed(() => {
    if (!targetRect.value) {
        return null
    }

    const pad = 6
    const r = targetRect.value

    return {
        top: `${r.top - pad}px`,
        left: `${r.left - pad}px`,
        width: `${r.width + pad * 2}px`,
        height: `${r.height + pad * 2}px`,
    }
})

// Tarjeta: debajo del target si hay espacio, si no arriba; centrada si el paso es introductorio.
const cardStyle = computed(() => {
    if (!targetRect.value) {
        return null
    }

    const r = targetRect.value
    const cardWidth = Math.min(340, window.innerWidth - 28)
    const spaceBelow = window.innerHeight - (r.top + r.height)
    const top = spaceBelow > 220 ? r.top + r.height + 14 : Math.max(14, r.top - 220)
    const left = Math.min(Math.max(14, r.left), window.innerWidth - cardWidth - 14)

    return { top: `${top}px`, left: `${left}px`, width: `${cardWidth}px` }
})
</script>

<template>
    <Teleport to="body">
        <div v-if="activeTourId && currentStep" class="pointer-events-none fixed inset-0 z-[70]">
            <!-- Anillo/spotlight sobre el elemento resaltado -->
            <div
                v-if="ringStyle"
                class="pointer-events-none fixed rounded-xl ring-2 ring-primary transition-all duration-300"
                :style="[ringStyle, { boxShadow: '0 0 0 9999px rgba(15, 23, 42, 0.55)' }]"
            />
            <!-- Atenuado completo para pasos introductorios (sin target) -->
            <div v-else class="pointer-events-none fixed inset-0 bg-slate-900/55" />

            <!-- Tarjeta informativa -->
            <div
                v-if="!searching"
                role="dialog"
                aria-live="polite"
                :aria-label="currentStep.title"
                class="pointer-events-auto fixed z-[71] rounded-2xl border border-border bg-card p-4 shadow-2xl"
                :style="cardStyle ?? { top: '50%', left: '50%', width: 'min(360px, calc(100vw - 28px))', transform: 'translate(-50%, -50%)' }"
            >
                <div class="flex items-start justify-between gap-2">
                    <p class="flex items-center gap-1.5 text-sm font-bold text-foreground"><Lightbulb class="size-4 text-primary" aria-hidden="true" /> {{ currentStep.title }}</p>
                    <button type="button" class="text-muted-foreground transition hover:text-foreground" aria-label="Cerrar recorrido (Esc)" @click="skip">
                        <X class="size-4" />
                    </button>
                </div>
                <p class="mt-2 text-sm leading-relaxed text-muted-foreground">{{ currentStep.body }}</p>
                <p v-if="currentStep.example" class="mt-1.5 text-xs italic leading-relaxed text-muted-foreground/80">{{ currentStep.example }}</p>

                <div class="mt-3 flex items-center justify-between gap-2">
                    <span class="whitespace-nowrap text-[11px] font-semibold text-muted-foreground">Paso {{ stepIndex + 1 }} de {{ totalSteps }}</span>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="rounded-lg px-2 py-1 text-xs font-bold text-muted-foreground transition hover:bg-muted" @click="skip">Saltar</button>
                        <button v-if="!isFirstStep" type="button" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-bold text-foreground transition hover:bg-muted" @click="prev">
                            <ArrowLeft class="size-3.5" /> Anterior
                        </button>
                        <button type="button" class="inline-flex items-center gap-1 rounded-lg bg-primary px-3 py-1.5 text-xs font-bold text-primary-foreground transition hover:bg-primary/90" @click="next">
                            {{ isLastStep ? 'Finalizar' : 'Siguiente' }} <ArrowRight v-if="!isLastStep" class="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
