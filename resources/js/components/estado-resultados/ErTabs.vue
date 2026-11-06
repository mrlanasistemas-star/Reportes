<script setup lang="ts">
import { useResizeObserver } from '@vueuse/core';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import type { Component } from 'vue';
import { useTabIndicator } from '@/composables/useTabIndicator';

export interface ErTab {
    key: string;
    label: string;
    icon: Component;
    badge?: string | number | null;
    /** Texto de la insignia para lectores de pantalla (p. ej. «32 por revisar»). */
    badgeLabel?: string | null;
    tone?: 'warn' | 'bad' | null;
    /** analisis = leer el resultado; cierre = trabajo para cerrar el mes. */
    group?: 'analisis' | 'cierre';
}

const props = defineProps<{ tabs: ErTab[] }>();
const active = defineModel<string>({ required: true });

const nav = ref<HTMLElement | null>(null);
const { indicatorStyle, indicatorReady } = useTabIndicator(
    nav,
    () => active.value,
);

/** Índice donde empieza el grupo «Cierre del mes» (para el separador). */
const closeStart = computed(() =>
    props.tabs.findIndex((t) => t.group === 'cierre'),
);

// Flechas y desvanecido en los bordes cuando no caben todas las pestañas.
const canLeft = ref(false);
const canRight = ref(false);

function measure(): void {
    const el = nav.value;

    if (!el) {
        return;
    }

    canLeft.value = el.scrollLeft > 4;
    canRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4;
}

function scrollBy(dir: 1 | -1): void {
    const el = nav.value;

    el?.scrollBy({ left: dir * el.clientWidth * 0.7, behavior: 'smooth' });
}

/** Mantiene visible la pestaña activa (también al llegar desde otro botón). */
function reveal(key: string): void {
    nav.value
        ?.querySelector<HTMLElement>(`[data-tab="${key}"]`)
        ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
}

useResizeObserver(nav, measure);
onMounted(() => {
    measure();
    reveal(active.value);
});
watch(active, (k) => nextTick(() => reveal(k)));

function focusTab(key: string): void {
    active.value = key;
    nav.value?.querySelector<HTMLElement>(`[data-tab="${key}"]`)?.focus();
}

function onKey(e: KeyboardEvent, i: number): void {
    const n = props.tabs.length;
    const target =
        e.key === 'ArrowRight'
            ? (i + 1) % n
            : e.key === 'ArrowLeft'
              ? (i - 1 + n) % n
              : e.key === 'Home'
                ? 0
                : e.key === 'End'
                  ? n - 1
                  : null;

    if (target === null) {
        return;
    }

    e.preventDefault();
    e.stopPropagation();
    focusTab(props.tabs[target].key);
}
</script>

<template>
    <div
        class="sticky top-0 z-20 -mx-4 border-b bg-background/90 px-4 backdrop-blur md:-mx-6 md:px-6"
    >
        <div class="relative">
            <nav
                ref="nav"
                class="relative flex items-stretch gap-0.5 overflow-x-auto scroll-smooth [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                role="tablist"
                aria-label="Secciones del Estado de Resultados"
                @scroll.passive="measure"
            >
                <template v-for="(t, i) in tabs" :key="t.key">
                    <span
                        v-if="i === closeStart && i > 0"
                        class="mx-2 my-3 flex shrink-0 items-center gap-2 border-l pl-3 text-xs font-medium whitespace-nowrap text-muted-foreground"
                        aria-hidden="true"
                        >Cierre del mes</span
                    >
                    <button
                        :id="`er-tab-${t.key}`"
                        type="button"
                        role="tab"
                        :data-tab="t.key"
                        :aria-selected="active === t.key"
                        :aria-controls="`er-panel-${t.key}`"
                        :tabindex="active === t.key ? 0 : -1"
                        class="relative my-1 flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-2.5 text-sm font-semibold whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-cyan-500 focus-visible:outline-none focus-visible:ring-inset"
                        :class="
                            active === t.key
                                ? 'text-foreground'
                                : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground'
                        "
                        @click="active = t.key"
                        @keydown="onKey($event, i)"
                    >
                        <component
                            :is="t.icon"
                            class="size-4"
                            :class="
                                active === t.key
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : ''
                            "
                            aria-hidden="true"
                        />
                        {{ t.label }}
                        <span
                            v-if="t.badge"
                            class="rounded-full px-1.5 py-px text-[10px] font-bold tabular-nums"
                            :class="
                                t.tone === 'bad'
                                    ? 'bg-rose-600 text-white'
                                    : 'bg-amber-300 text-amber-950 dark:bg-amber-400'
                            "
                            aria-hidden="true"
                            >{{ t.badge }}</span
                        >
                        <span v-if="t.badge" class="sr-only"
                            >({{ t.badgeLabel ?? t.badge }})</span
                        >
                    </button>
                </template>
                <span
                    class="app-tab-indicator"
                    :style="indicatorStyle"
                    :data-ready="indicatorReady"
                    aria-hidden="true"
                />
            </nav>

            <!-- Pistas de que hay más pestañas a los lados -->
            <div
                class="pointer-events-none absolute inset-y-0 left-0 flex items-center bg-gradient-to-r from-background via-background/90 to-transparent pr-6 transition-opacity duration-200"
                :class="canLeft ? 'opacity-100' : 'opacity-0'"
            >
                <button
                    type="button"
                    tabindex="-1"
                    class="pointer-events-auto flex size-7 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-sm transition hover:text-foreground"
                    :class="canLeft ? '' : 'invisible'"
                    aria-label="Ver pestañas anteriores"
                    @click="scrollBy(-1)"
                >
                    <ChevronLeft class="size-4" />
                </button>
            </div>
            <div
                class="pointer-events-none absolute inset-y-0 right-0 flex items-center bg-gradient-to-l from-background via-background/90 to-transparent pl-6 transition-opacity duration-200"
                :class="canRight ? 'opacity-100' : 'opacity-0'"
            >
                <button
                    type="button"
                    tabindex="-1"
                    class="pointer-events-auto flex size-7 items-center justify-center rounded-full border bg-card text-muted-foreground shadow-sm transition hover:text-foreground"
                    :class="canRight ? '' : 'invisible'"
                    aria-label="Ver más pestañas"
                    @click="scrollBy(1)"
                >
                    <ChevronRight class="size-4" />
                </button>
            </div>
        </div>
    </div>
</template>
