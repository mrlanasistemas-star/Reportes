/**
 * Recorridos guiados (tours) del sistema: estado global del recorrido activo,
 * navegación entre pasos (incluso entre pantallas vía Inertia), filtrado de
 * pasos por permisos del rol y registro en localStorage de los ya vistos.
 * Los recorridos se definen en resources/js/tour/registry.
 */
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { visibleMainNavItems } from '@/config/mainNav';
import { toUrl } from '@/lib/utils';
import { TOURS } from '@/tour/registry';
import type { TourStep } from '@/tour/types';

// Estado GLOBAL (singleton) — mismo patrón ya usado en este repo para estado
// compartido sin Pinia (ver routeLoadingOverlay.ts). Un solo overlay montado
// una vez en AppSidebar.vue, visible sin importar qué página de Inertia esté
// activa — "los recorridos sobreviven a la navegación Inertia" (Fase 2).
/** Id del recorrido activo (null = ninguno). */
const activeTourId = ref<string | null>(null);
/** Índice del paso actual dentro del recorrido activo. */
const stepIndex = ref(0);
/** true mientras Inertia navega hacia la pantalla del paso. */
const pendingNavigation = ref(false);
/** Sentido del último movimiento: el overlay salta en esa dirección si el paso no tiene elemento. */
const lastDirection = ref<1 | -1>(1);
/** Pantalla preferida para el primer paso (la de origen, si es del mismo módulo). */
let preferredStartPath: string | null = null;

/** Llave de localStorage con los ids de recorridos ya vistos. */
const STORAGE_KEY = 'reportes_tours_completed_v1';

/** Lee los recorridos completados desde localStorage ([] si no hay o falla). */
function readCompleted(): string[] {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    } catch {
        return [];
    }
}

/** Guarda los recorridos completados en localStorage (ignora errores de almacenamiento). */
function writeCompleted(ids: string[]): void {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
    } catch {
        // Almacenamiento bloqueado (ventana privada): el recorrido sigue funcionando.
    }
}

/** Ids de recorridos ya vistos o saltados. */
const completedTours = ref<string[]>(readCompleted());

/** Primer segmento de una ruta ('/okr/5?x=1' → 'okr'); identifica el módulo. */
const firstSegment = (path: string): string =>
    path.split('?')[0].split('/').filter(Boolean)[0] ?? '';

/** Composable que expone el estado del recorrido y las acciones iniciar/siguiente/anterior/saltar/terminar. */
export function useTour() {
    const page = usePage();

    /**
     * Fase 8.13: un paso que vive en otra pantalla solo se muestra si el rol
     * puede abrir esa pantalla (mismo filtro que el menú). Si no, se salta:
     * la guía nunca lleva a un 403 ni explica un botón que el usuario no tiene.
     */
    function stepAllowed(step: TourStep): boolean {
        if (!step.href && !step.module) {
            return true;
        }

        const allowed = visibleMainNavItems(page.props.auth).map((item) =>
            firstSegment(toUrl(item.href)),
        );

        // Un paso que declara `module` (aunque no tenga href) solo se muestra si el
        // rol ve ese módulo: así un recorrido nunca describe un módulo ajeno al alcance.
        if (step.module && !allowed.includes(step.module)) {
            return false;
        }

        return step.href ? allowed.includes(firstSegment(step.href)) : true;
    }

    /** Definición del recorrido activo, paso actual, total y si es el primero/último. */
    const activeTour = computed(() =>
        activeTourId.value ? TOURS[activeTourId.value] : null,
    );
    const currentStep = computed<TourStep | null>(
        () => activeTour.value?.steps[stepIndex.value] ?? null,
    );
    const totalSteps = computed(() => activeTour.value?.steps.length ?? 0);
    const isFirstStep = computed(() => stepIndex.value === 0);
    const isLastStep = computed(() => stepIndex.value >= totalSteps.value - 1);

    /** true si el recorrido ya se vio (completado o saltado). */
    function hasSeenTour(id: string): boolean {
        return completedTours.value.includes(id);
    }

    /** Marca un recorrido como visto y lo persiste. */
    function markSeen(id: string): void {
        if (!completedTours.value.includes(id)) {
            completedTours.value = [...completedTours.value, id];
            writeCompleted(completedTours.value);
        }
    }

    /**
     * Va al paso `index` (acotado al rango), saltando en la dirección del avance
     * los pasos no permitidos; si no queda ninguno hacia adelante, termina el
     * recorrido. Navega al href del paso si es de otro módulo (Inertia) y deja
     * que el overlay busque el elemento objetivo.
     */
    async function goToStep(index: number): Promise<void> {
        const tour = activeTour.value;

        if (!tour) {
            return;
        }

        let clamped = Math.max(0, Math.min(index, tour.steps.length - 1));
        const direction = index < stepIndex.value ? -1 : 1;

        // Saltar pasos de pantallas no permitidas en la dirección del avance.
        while (!stepAllowed(tour.steps[clamped])) {
            const nextIndex = clamped + direction;

            if (nextIndex < 0 || nextIndex >= tour.steps.length) {
                if (direction > 0) {
                    finish();
                }

                return;
            }

            clamped = nextIndex;
        }

        const step = tour.steps[clamped];
        lastDirection.value = direction;
        stepIndex.value = clamped;

        // Si ya estás dentro del mismo módulo (p. ej. el detalle /okr/5 y el paso
        // apunta a /okr), no se te saca de la pantalla en la que estás.
        const preferred = preferredStartPath;
        preferredStartPath = null;
        const destination =
            step.href &&
            preferred &&
            firstSegment(preferred) === firstSegment(step.href)
                ? preferred
                : step.href;

        if (
            destination &&
            firstSegment(destination) !== firstSegment(window.location.pathname)
        ) {
            pendingNavigation.value = true;
            router.visit(destination, {
                onFinish: () => {
                    pendingNavigation.value = false;
                },
            });
        }
    }

    /**
     * Inicia un recorrido desde el primer paso.
     * @param fromPath pantalla de origen, preferida si el primer paso es del mismo módulo.
     */
    function startTour(id: string, fromPath: string | null = null): void {
        if (!TOURS[id]) {
            return;
        }

        preferredStartPath = fromPath;

        activeTourId.value = id;
        stepIndex.value = 0;
        lastDirection.value = 1;
        void goToStep(0);
    }

    /** Avanza un paso o termina si es el último. */
    function next(): void {
        if (!activeTour.value) {
            return;
        }

        if (isLastStep.value) {
            finish();

            return;
        }

        void goToStep(stepIndex.value + 1);
    }

    /** Retrocede un paso (no hace nada en el primero). */
    function prev(): void {
        if (!activeTour.value || isFirstStep.value) {
            return;
        }

        void goToStep(stepIndex.value - 1);
    }

    /** Cierra el recorrido marcándolo como visto (para no volver a ofrecerlo). */
    function skip(): void {
        if (activeTourId.value) {
            markSeen(activeTourId.value);
        }

        activeTourId.value = null;
        stepIndex.value = 0;
    }

    /** Termina el recorrido marcándolo como visto. */
    function finish(): void {
        if (activeTourId.value) {
            markSeen(activeTourId.value);
        }

        activeTourId.value = null;
        stepIndex.value = 0;
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
        lastDirection,
        startTour,
        next,
        prev,
        skip,
        finish,
        hasSeenTour,
        markSeen,
    };
}
