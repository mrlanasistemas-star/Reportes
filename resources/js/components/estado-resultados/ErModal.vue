<script setup lang="ts">
/**
 * ErModal — modal centrado y flotante del Estado de Resultados.
 *
 * Lo usan ValidationPanel (detalle de cada bloque) y MovementDetailSheet (detalle de un
 * movimiento). Entra con fundido + escala + leve deslizamiento, tiene encabezado fijo
 * (icono, título, descripción e indicador), una barra de herramientas fija opcional
 * (filtros/búsqueda), un cuerpo con desplazamiento interno suave y un pie fijo opcional
 * (acciones). Alto máximo ~85vh: la página de atrás no se desplaza.
 *
 * Props:
 * - open: si el modal está abierto (v-model:open).
 * - title / description: encabezado (la descripción es opcional y va en una línea).
 * - icon / tone: icono del encabezado y su color.
 * - size: ancho máximo (md, lg, xl, 2xl).
 * - scroll: false cuando el contenido maneja su propio desplazamiento (listas con barra fija).
 * - bodyClass: clases extra del cuerpo.
 *
 * Slots: badge (indicador a la derecha del título), toolbar, default, footer.
 *
 * Emits:
 * - update:open: abrir o cerrar.
 */
import { X } from 'lucide-vue-next';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import type { Component } from 'vue';
import { cn } from '@/lib/utils';
import { TONE_ICON } from './erTone';
import type { ErTone } from './erTone';

withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string | null;
        icon?: Component | null;
        tone?: ErTone;
        size?: 'md' | 'lg' | 'xl' | '2xl';
        scroll?: boolean;
        bodyClass?: string;
    }>(),
    {
        description: null,
        icon: null,
        tone: 'emerald',
        size: 'xl',
        scroll: true,
        bodyClass: '',
    },
);
const emit = defineEmits<{ 'update:open': [v: boolean] }>();

/** Ancho máximo por tamaño. */
const SIZE: Record<'md' | 'lg' | 'xl' | '2xl', string> = {
    md: 'sm:max-w-xl',
    lg: 'sm:max-w-3xl',
    xl: 'sm:max-w-5xl',
    '2xl': 'sm:max-w-6xl',
};
</script>

<template>
    <DialogRoot :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-slate-950/55 backdrop-blur-[3px] duration-300 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0 motion-reduce:animate-none"
            />
            <DialogContent
                :class="
                    cn(
                        'fixed top-1/2 left-1/2 z-50 flex max-h-[92dvh] w-[calc(100%-1.5rem)] -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-3xl border bg-background text-[15px] shadow-2xl ring-1 shadow-slate-950/20 ring-black/5 outline-none sm:max-h-[85vh] dark:ring-white/10',
                        'duration-300 ease-out data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-95 data-[state=closed]:slide-out-to-bottom-2 data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95 data-[state=open]:slide-in-from-bottom-6 motion-reduce:animate-none',
                        SIZE[size],
                    )
                "
            >
                <!-- Encabezado fijo -->
                <header
                    class="relative flex shrink-0 items-start gap-3 border-b bg-gradient-to-b from-muted/50 to-background px-5 py-4 sm:px-6"
                >
                    <span
                        v-if="icon"
                        class="grid size-11 shrink-0 place-items-center rounded-2xl ring-1"
                        :class="TONE_ICON[tone]"
                        aria-hidden="true"
                    >
                        <component :is="icon" class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <DialogTitle
                            class="text-lg leading-tight font-extrabold tracking-tight sm:text-xl"
                        >
                            {{ title }}
                        </DialogTitle>
                        <DialogDescription
                            v-if="description"
                            class="mt-1 line-clamp-2 text-sm text-muted-foreground"
                            :title="description"
                        >
                            {{ description }}
                        </DialogDescription>
                        <DialogDescription v-else class="sr-only">
                            {{ title }}
                        </DialogDescription>
                    </div>
                    <div
                        v-if="$slots.badge"
                        class="hidden shrink-0 items-center gap-2 self-center sm:flex"
                    >
                        <slot name="badge" />
                    </div>
                    <DialogClose
                        class="-mt-1 -mr-1 grid size-9 shrink-0 place-items-center rounded-full text-muted-foreground transition hover:rotate-90 hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:outline-none"
                        aria-label="Cerrar"
                    >
                        <X class="size-5" />
                    </DialogClose>
                </header>
                <div
                    v-if="$slots.badge"
                    class="flex shrink-0 flex-wrap items-center gap-2 border-b px-5 py-2 sm:hidden"
                >
                    <slot name="badge" />
                </div>

                <!-- Barra fija (filtros / búsqueda) -->
                <div
                    v-if="$slots.toolbar"
                    class="shrink-0 border-b bg-muted/25 px-5 py-3 sm:px-6"
                >
                    <slot name="toolbar" />
                </div>

                <!-- Cuerpo -->
                <div
                    :class="
                        cn(
                            scroll
                                ? 'min-h-0 flex-1 overflow-y-auto overscroll-contain scroll-smooth px-5 py-5 sm:px-6'
                                : 'flex min-h-0 flex-1 flex-col overflow-hidden',
                            bodyClass,
                        )
                    "
                >
                    <slot />
                </div>

                <!-- Pie fijo (acciones) -->
                <footer
                    v-if="$slots.footer"
                    class="flex shrink-0 flex-wrap items-center gap-2 border-t bg-muted/30 px-5 py-3 sm:px-6"
                >
                    <slot name="footer" />
                </footer>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
