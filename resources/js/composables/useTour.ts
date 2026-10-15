import { router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { TOURS } from '@/tour/registry'
import type { TourStep } from '@/tour/types'

// Estado GLOBAL (singleton) — mismo patrón ya usado en este repo para estado
// compartido sin Pinia (ver routeLoadingOverlay.ts). Un solo overlay montado
// una vez en AppSidebar.vue, visible sin importar qué página de Inertia esté
// activa — "los recorridos sobreviven a la navegación Inertia" (Fase 2).
const activeTourId = ref<string | null>(null)
const stepIndex = ref(0)
const pendingNavigation = ref(false)

const STORAGE_KEY = 'reportes_tours_completed_v1'

function readCompleted(): string[] {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]')
    } catch {
        return []
    }
}

function writeCompleted(ids: string[]): void {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(ids))
}

const completedTours = ref<string[]>(readCompleted())

export function useTour() {
    const activeTour = computed(() => (activeTourId.value ? TOURS[activeTourId.value] : null))
    const currentStep = computed<TourStep | null>(() => activeTour.value?.steps[stepIndex.value] ?? null)
    const totalSteps = computed(() => activeTour.value?.steps.length ?? 0)
    const isFirstStep = computed(() => stepIndex.value === 0)
    const isLastStep = computed(() => stepIndex.value >= totalSteps.value - 1)

    function hasSeenTour(id: string): boolean {
        return completedTours.value.includes(id)
    }

    function markSeen(id: string): void {
        if (!completedTours.value.includes(id)) {
            completedTours.value = [...completedTours.value, id]
            writeCompleted(completedTours.value)
        }
    }

    /** Navega al href del paso si hace falta (Inertia), luego deja que el overlay busque el target. */
    async function goToStep(index: number): Promise<void> {
        const tour = activeTour.value
        if (!tour) {
            return
        }

        const clamped = Math.max(0, Math.min(index, tour.steps.length - 1))
        const step = tour.steps[clamped]
        stepIndex.value = clamped

        if (step.href && step.href !== window.location.pathname) {
            pendingNavigation.value = true
            router.visit(step.href, {
                onFinish: () => {
                    pendingNavigation.value = false
                },
            })
        }
    }

    function startTour(id: string): void {
        if (!TOURS[id]) {
            return
        }

        activeTourId.value = id
        stepIndex.value = 0
        void goToStep(0)
    }

    function next(): void {
        if (!activeTour.value) {
            return
        }

        if (isLastStep.value) {
            finish()

            return
        }

        void goToStep(stepIndex.value + 1)
    }

    function prev(): void {
        if (!activeTour.value || isFirstStep.value) {
            return
        }

        void goToStep(stepIndex.value - 1)
    }

    function skip(): void {
        if (activeTourId.value) {
            markSeen(activeTourId.value)
        }
        activeTourId.value = null
        stepIndex.value = 0
    }

    function finish(): void {
        if (activeTourId.value) {
            markSeen(activeTourId.value)
        }
        activeTourId.value = null
        stepIndex.value = 0
    }

    return {
        activeTourId,
        activeTour,
        currentStep,
        stepIndex,
        totalSteps,
        isFirstStep,
        isLastStep,
        pendingNavigation,
        startTour,
        next,
        prev,
        skip,
        finish,
        hasSeenTour,
        markSeen,
    }
}
