<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Superficie de los selectores del Estado de Resultados: popover anclado en pantallas
 * medianas y grandes; hoja inferior (bottom sheet) en teléfonos. El contenido es el mismo.
 *
 * El disparador recibe `toggle` (solo hace algo en teléfono; en escritorio lo maneja el
 * popover). `open-focus` permite al contenido decidir qué enfocar al abrir; al cerrar el
 * foco vuelve siempre al disparador.
 */
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        align?: 'start' | 'center' | 'end';
        contentClass?: HTMLAttributes['class'];
    }>(),
    { description: undefined, align: 'start', contentClass: undefined },
);

const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ openFocus: [e: Event] }>();
const desktop = useMediaQuery('(min-width: 640px)');

let returnTo: HTMLElement | null = null;

function rememberTrigger(e: Event): void {
    returnTo = (e.currentTarget as HTMLElement | null) ?? null;
}

function toggle(e: Event): void {
    rememberTrigger(e);

    if (!desktop.value) {
        open.value = !open.value;
    }
}

function onCloseFocus(e: Event): void {
    if (returnTo) {
        e.preventDefault();
        returnTo.focus();
    }
}
</script>

<template>
    <PopoverRoot v-if="desktop" v-model:open="open">
        <PopoverTrigger as-child>
            <slot name="trigger" :toggle="toggle" :open="open" />
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                :align="align"
                :side-offset="8"
                :collision-padding="12"
                :aria-label="title"
                :class="
                    cn(
                        'er-picker-pop z-50 origin-(--reka-popover-content-transform-origin) overflow-hidden rounded-2xl border bg-popover text-popover-foreground shadow-[0_24px_48px_-20px_rgb(15_23_42/0.35),0_2px_6px_-2px_rgb(15_23_42/0.12)] outline-hidden dark:shadow-[0_24px_48px_-16px_rgb(0_0_0/0.7)]',
                        contentClass,
                    )
                "
                @open-auto-focus="emit('openFocus', $event)"
                @close-auto-focus="onCloseFocus"
            >
                <slot />
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>

    <template v-else>
        <slot name="trigger" :toggle="toggle" :open="open" />
        <DialogRoot v-model:open="open">
            <DialogPortal>
                <DialogOverlay
                    class="er-sheet-overlay fixed inset-0 z-50 bg-slate-950/40 backdrop-blur-[2px]"
                />
                <DialogContent
                    class="er-sheet fixed inset-x-0 bottom-0 z-50 flex max-h-[85dvh] flex-col overflow-hidden rounded-t-3xl border-t bg-popover pb-[env(safe-area-inset-bottom)] text-popover-foreground shadow-[0_-24px_48px_-24px_rgb(15_23_42/0.45)] outline-hidden"
                    @open-auto-focus="emit('openFocus', $event)"
                    @close-auto-focus="onCloseFocus"
                >
                    <div
                        class="mx-auto mt-2.5 h-1 w-10 shrink-0 rounded-full bg-muted-foreground/25"
                        aria-hidden="true"
                    />
                    <DialogTitle class="sr-only">{{ title }}</DialogTitle>
                    <DialogDescription class="sr-only">{{
                        description ?? title
                    }}</DialogDescription>
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <slot />
                    </div>
                </DialogContent>
            </DialogPortal>
        </DialogRoot>
    </template>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    :global(.er-picker-pop[data-state='open']) {
        animation: er-pop-in 180ms cubic-bezier(0.2, 0.9, 0.3, 1.2);
    }
    :global(.er-picker-pop[data-state='closed']) {
        animation: er-pop-out 120ms ease-in forwards;
    }
    :global(.er-sheet[data-state='open']) {
        animation: er-sheet-in 280ms cubic-bezier(0.2, 0.9, 0.25, 1);
    }
    :global(.er-sheet[data-state='closed']) {
        animation: er-sheet-out 180ms ease-in forwards;
    }
    :global(.er-sheet-overlay[data-state='open']) {
        animation: er-fade-in 200ms ease-out;
    }
    :global(.er-sheet-overlay[data-state='closed']) {
        animation: er-fade-out 160ms ease-in forwards;
    }
}

@keyframes er-pop-in {
    from {
        opacity: 0;
        transform: translateY(-4px) scale(0.97);
    }
}
@keyframes er-pop-out {
    to {
        opacity: 0;
        transform: translateY(-2px) scale(0.98);
    }
}
@keyframes er-sheet-in {
    from {
        transform: translateY(100%);
    }
}
@keyframes er-sheet-out {
    to {
        transform: translateY(100%);
    }
}
@keyframes er-fade-in {
    from {
        opacity: 0;
    }
}
@keyframes er-fade-out {
    to {
        opacity: 0;
    }
}
</style>
