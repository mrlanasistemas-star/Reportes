<script setup lang="ts">
/**
 * Pantalla: GUÍA DEL SISTEMA / Centro de ayuda (/guia-sistema).
 *
 * Para qué sirve (rediseño 16-oct-2026): búsqueda de módulos/temas, un módulo
 * enfocado cuando se llega desde el botón flotante (?modulo=okr&desde=/ruta),
 * tarjetas por módulo con su recorrido guiado sobre la pantalla real y el
 * manual ilustrado paso a paso (GuideManual.vue — mismo contenido validado).
 *
 * Origen de los datos: `App\Http\Controllers\SystemGuideController@index`
 * renderiza `SystemGuide/Index` SIN props propias; todo sale de las props
 * compartidas de Inertia (`auth.modules`, para filtrar por rol) y de la URL.
 * El catálogo de módulos vive en `@/tour/modules`.
 *
 * Rutas que usa: `/guia-sistema/pdf` (descarga de la guía en PDF) y
 * `/guia-sistema` (para limpiar el módulo enfocado del historial).
 */
import { Head, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    BookOpen,
    Compass,
    Download,
    LifeBuoy,
    PlayCircle,
    Search,
    Sparkles,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, onMounted, ref } from 'vue';
import GuideManual from '@/components/guide/GuideManual.vue';
import GuideModuleCard from '@/components/guide/GuideModuleCard.vue';
import GuideModuleFacts from '@/components/guide/GuideModuleFacts.vue';
import { useTour } from '@/composables/useTour';
import AppLayout from '@/layouts/AppLayout.vue';
import { guideModulesFor, moduleById, welcomeTourFor } from '@/tour/modules';

defineOptions({ layout: AppLayout });

const page = usePage();
// Lanza un recorrido guiado (driver) por id, opcionalmente volviendo a una ruta.
const { startTour } = useTour();
/** Texto del buscador de módulos/temas. */
const search = ref('');
/** Paso del manual que debe abrirse/resaltarse (null = ninguno). */
const manualFocus = ref<string | null>(null);

/** Id del módulo enfocado, leído del parámetro `?modulo=` de la URL. */
const focusedId = ref<string | null>(
    new URL(page.url, window.location.origin).searchParams.get('modulo'),
);
/**
 * Pantalla desde la que se abrió la ayuda (`?desde=`). Solo se aceptan rutas
 * internas ("/..." y no "//...") para evitar redirecciones abiertas.
 */
const fromPath = (() => {
    const raw = new URL(page.url, window.location.origin).searchParams.get(
        'desde',
    );

    return raw && raw.startsWith('/') && !raw.startsWith('//') ? raw : null;
})();
/** Fase 8.13: solo los módulos que el rol puede abrir (mismo criterio que el menú). */
const allowedModules = computed(() =>
    guideModulesFor(page.props.auth?.modules),
);
/** Módulo enfocado, solo si existe y el rol tiene acceso a él; si no, null. */
const focused = computed(() => {
    const found = moduleById(focusedId.value);

    return found && allowedModules.value.some((m) => m.id === found.id)
        ? found
        : null;
});
/** Recorrido de bienvenida adecuado a los módulos del rol (null si no hay). */
const welcomeTour = computed(() => welcomeTourFor(page.props.auth?.modules));
/**
 * El manual ilustrado explica el ciclo de cierre (periodos → carga → reporte):
 * solo tiene sentido para quien puede usar alguno de esos módulos.
 */
const showManual = computed(() =>
    ['carga', 'periodos', 'reportes', 'dashboard'].some((key) =>
        (page.props.auth?.modules ?? []).includes(key),
    ),
);

/**
 * Tarjetas de módulos a mostrar: los permitidos menos el enfocado, filtrados
 * por la búsqueda (etiqueta, descripción y temas, sin distinguir mayúsculas).
 */
const visibleModules = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = allowedModules.value.filter((m) => m.id !== focused.value?.id);

    return q === ''
        ? list
        : list.filter((m) =>
              [m.label, m.description, ...m.topics]
                  .join(' ')
                  .toLowerCase()
                  .includes(q),
          );
});

/** Enfoca un paso del manual y desplaza la vista hasta el manual. */
function openManual(step: string): void {
    manualFocus.value = step;
    nextTick(() =>
        document
            .getElementById('guide-manual')
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
    );
}

/**
 * Quita el módulo enfocado y limpia la URL (sin recargar ni navegar con Inertia)
 * para volver a ver todos los módulos.
 */
function clearFocus(): void {
    focusedId.value = null;
    window.history.replaceState({}, '', '/guia-sistema');
}

/** Al montar: si el módulo enfocado tiene paso de manual, lo abre de inicio. */
onMounted(() => {
    if (focused.value?.manualStep) {
        manualFocus.value = focused.value.manualStep;
    }
});
</script>

<template>
    <Head title="Guía del sistema" />

    <div class="mx-auto max-w-screen-2xl space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- Encabezado -->
        <section class="rounded-2xl border bg-card p-5 shadow-soft-xs sm:p-8">
            <div
                class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between"
            >
                <div class="max-w-2xl">
                    <p class="app-kicker inline-flex items-center gap-1.5">
                        <LifeBuoy class="size-3.5" aria-hidden="true" /> Centro
                        de ayuda
                    </p>
                    <h1 class="app-title mt-2">¿Qué quieres aprender hoy?</h1>
                    <p class="app-subtitle mt-1">
                        Recorridos guiados sobre la pantalla real de los módulos
                        que tienes habilitados y un manual paso a paso. La guía
                        nunca bloquea: puedes salir en cualquier momento.
                    </p>
                    <div class="relative mt-5">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            v-model="search"
                            type="search"
                            autocomplete="off"
                            aria-label="Buscar en la guía"
                            placeholder="Busca un módulo o tema: OKR, línea base, comparativos…"
                            class="app-input h-11 rounded-xl pl-10"
                        />
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="welcomeTour"
                        type="button"
                        class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-4 text-sm font-semibold text-foreground shadow-soft-xs transition hover:bg-muted"
                        @click="startTour(welcomeTour)"
                    >
                        <Compass class="size-4" aria-hidden="true" /> Repetir
                        recorrido de bienvenida
                    </button>
                    <a
                        href="/guia-sistema/pdf"
                        class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground transition hover:bg-primary/90"
                    >
                        <Download class="size-4" aria-hidden="true" /> Descargar
                        guía en PDF
                    </a>
                </div>
            </div>
        </section>

        <!-- Módulo enfocado (desde el botón de ayuda) -->
        <section
            v-if="focused"
            class="relative rounded-2xl border border-primary/30 bg-accent/40 p-5 sm:p-6"
        >
            <button
                type="button"
                class="absolute top-3 right-3 rounded-lg p-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                aria-label="Ver todos los módulos"
                @click="clearFocus"
            >
                <X class="size-4" aria-hidden="true" />
            </button>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
                <span
                    class="flex size-12 shrink-0 items-center justify-center rounded-xl border bg-card text-primary shadow-soft-xs"
                >
                    <component
                        :is="focused.icon"
                        class="size-6"
                        aria-hidden="true"
                    />
                </span>
                <div class="min-w-0 flex-1 pr-6">
                    <p
                        class="app-kicker inline-flex items-center gap-1.5 text-primary"
                    >
                        <Sparkles class="size-3.5" aria-hidden="true" /> Estás
                        en este módulo
                    </p>
                    <h2
                        class="mt-1 text-xl font-semibold tracking-tight text-foreground"
                    >
                        {{ focused.label }}
                    </h2>
                    <p class="mt-0.5 text-sm text-muted-foreground">
                        {{ focused.description }}
                    </p>
                    <GuideModuleFacts
                        class="mt-3 max-w-3xl"
                        :module="focused"
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-if="focused.tourId"
                        type="button"
                        class="inline-flex h-10 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground transition hover:bg-primary/90"
                        @click="startTour(focused.tourId, fromPath)"
                    >
                        <PlayCircle class="size-4" aria-hidden="true" /> Iniciar
                        recorrido
                        <ArrowRight class="size-4" aria-hidden="true" />
                    </button>
                    <button
                        v-if="focused.manualStep && showManual"
                        type="button"
                        class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-4 text-sm font-semibold text-foreground transition hover:bg-muted"
                        @click="openManual(focused.manualStep)"
                    >
                        <BookOpen class="size-4" aria-hidden="true" /> Ver en el
                        manual
                    </button>
                </div>
            </div>
        </section>

        <!-- Módulos -->
        <section class="space-y-3" aria-labelledby="guide-modules-title">
            <div class="flex items-end justify-between gap-2">
                <h2 id="guide-modules-title" class="app-section-title text-lg">
                    {{
                        focused
                            ? 'Otros módulos'
                            : 'Módulos disponibles para ti'
                    }}
                </h2>
                <span class="text-xs text-muted-foreground" aria-live="polite"
                    >{{ visibleModules.length }} resultado(s)</span
                >
            </div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <GuideModuleCard
                    v-for="m in visibleModules"
                    :key="m.id"
                    :module="showManual ? m : { ...m, manualStep: undefined }"
                    @tour="startTour"
                    @manual="openManual"
                />
            </div>
            <p
                v-if="!visibleModules.length"
                class="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground"
            >
                Ningún módulo coincide con “{{ search }}”.
            </p>
        </section>

        <!-- Manual ilustrado -->
        <section
            v-if="showManual"
            class="space-y-3"
            aria-labelledby="guide-manual-title"
        >
            <div class="flex items-center gap-2">
                <BookOpen class="size-5 text-primary" aria-hidden="true" />
                <h2 id="guide-manual-title" class="app-section-title text-lg">
                    Manual paso a paso
                </h2>
            </div>
            <GuideManual :focus="manualFocus" />
        </section>
    </div>
</template>
