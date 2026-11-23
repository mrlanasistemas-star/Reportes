<script setup lang="ts" generic="K extends string">
/**
 * RadiographyTabBar — barra de pestañas accesible de la radiografía (ReportesMensuales/Preview).
 *
 * Props:
 * - tabs: pestañas { key, label } en orden.
 * - idPrefix: prefijo de ids compartido con RadiographyTabPanel.
 * - label: nombre accesible de la lista de pestañas.
 *
 * v-model: clave de la pestaña activa.
 */
// Barra de pestañas de la radiografía con el patrón ARIA "tabs":
// role=tablist/tab, aria-selected, aria-controls, foco itinerante (solo la
// pestaña activa es tabulable) y navegación con ←/→, Inicio y Fin. Conserva
// el scroll horizontal con flechas cuando no caben todas las pestañas.
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { tabDomId, tabPanelDomId } from './tabIds';

const props = defineProps<{
    tabs: { key: K; label: string }[];
    /** Prefijo de ids (pestaña y panel) — debe coincidir con RadiographyTabPanel. */
    idPrefix: string;
    /** Nombre accesible de la lista de pestañas. */
    label: string;
}>();

const active = defineModel<K>({ required: true });

/** Contenedor desplazable de las pestañas. */
const scrollEl = ref<HTMLElement | null>(null);
/** Indica si hay pestañas ocultas a la izquierda / derecha (muestran las flechas). */
const canScrollLeft = ref(false);
const canScrollRight = ref(false);

/** Recalcula si se puede desplazar a la izquierda o a la derecha. */
function updateScrollState() {
    const el = scrollEl.value;

    if (!el) {
        return;
    }

    canScrollLeft.value = el.scrollLeft > 4;
    canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4;
}

/**
 * Desplaza la barra 160 px en la dirección indicada.
 *
 * @param direction 'left' o 'right'.
 */
function scrollTabs(direction: 'left' | 'right') {
    scrollEl.value?.scrollBy({
        left: direction === 'left' ? -160 : 160,
        behavior: 'smooth',
    });
}

/**
 * Devuelve el botón de la pestaña con la clave dada.
 *
 * @param key Clave de la pestaña.
 */
function tabButton(key: K): HTMLElement | null {
    return (
        scrollEl.value?.querySelector<HTMLElement>(`[data-tab-key="${key}"]`) ??
        null
    );
}

/**
 * Activa una pestaña y, opcionalmente, le pasa el foco.
 *
 * @param key Clave de la pestaña.
 * @param focus Si es true enfoca el botón tras activarlo.
 */
function select(key: K, focus = false) {
    active.value = key;

    if (focus) {
        nextTick(() => tabButton(key)?.focus());
    }
}

/**
 * Navegación con ←/→ (circular), Inicio y Fin entre pestañas.
 *
 * @param event Evento de teclado en la lista de pestañas.
 */
function onKeydown(event: KeyboardEvent) {
    const keys = props.tabs.map((t) => t.key);
    const index = keys.indexOf(active.value);
    let next: number | null = null;

    switch (event.key) {
        case 'ArrowRight':
            next = (index + 1) % keys.length;
            break;
        case 'ArrowLeft':
            next = (index - 1 + keys.length) % keys.length;
            break;
        case 'Home':
            next = 0;
            break;
        case 'End':
            next = keys.length - 1;
            break;
    }

    if (next !== null) {
        event.preventDefault();
        select(keys[next], true);
    }
}

/** Al cambiar de pestaña la centra en la vista y actualiza las flechas. */
watch(active, (key) => {
    nextTick(() => {
        tabButton(key)?.scrollIntoView({
            behavior: 'smooth',
            inline: 'center',
            block: 'nearest',
        });
        updateScrollState();
    });
});

/** Registra (y al desmontar elimina) los listeners de scroll y resize. */
onMounted(() => {
    updateScrollState();
    scrollEl.value?.addEventListener('scroll', updateScrollState, {
        passive: true,
    });
    window.addEventListener('resize', updateScrollState);
});

onUnmounted(() => {
    scrollEl.value?.removeEventListener('scroll', updateScrollState);
    window.removeEventListener('resize', updateScrollState);
});
</script>

<template>
    <div class="relative border-b border-border">
        <button
            v-if="canScrollLeft"
            type="button"
            tabindex="-1"
            class="absolute top-0 left-0 z-10 flex h-full items-center bg-gradient-to-r from-background via-background/90 to-transparent pr-4 pl-1 text-muted-foreground hover:text-primary"
            aria-label="Ver pestañas anteriores"
            @click="scrollTabs('left')"
        >
            <ChevronLeft aria-hidden="true" class="size-4" />
        </button>
        <div
            ref="scrollEl"
            role="tablist"
            :aria-label="label"
            class="flex gap-1 overflow-x-auto scroll-smooth"
            @keydown="onKeydown"
        >
            <button
                v-for="t in tabs"
                :id="tabDomId(idPrefix, t.key)"
                :key="t.key"
                type="button"
                role="tab"
                :data-tab-key="t.key"
                :aria-selected="active === t.key"
                :aria-controls="tabPanelDomId(idPrefix, t.key)"
                :tabindex="active === t.key ? 0 : -1"
                class="relative shrink-0 border-b-2 px-3.5 py-2.5 text-xs font-bold whitespace-nowrap transition focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                :class="
                    active === t.key
                        ? 'border-primary text-primary'
                        : 'border-transparent text-muted-foreground hover:text-foreground'
                "
                @click="select(t.key)"
            >
                {{ t.label }}
            </button>
        </div>
        <button
            v-if="canScrollRight"
            type="button"
            tabindex="-1"
            class="absolute top-0 right-0 z-10 flex h-full items-center bg-gradient-to-l from-background via-background/90 to-transparent pr-1 pl-4 text-muted-foreground hover:text-primary"
            aria-label="Ver más pestañas"
            @click="scrollTabs('right')"
        >
            <ChevronRight aria-hidden="true" class="size-4" />
        </button>
    </div>
</template>
