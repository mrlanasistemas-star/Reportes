<script setup lang="ts">
/**
 * ErDisclosure — sección plegable con icono para información secundaria.
 *
 * La usa MovementDetailSheet (conciliación, evidencia, datos bancarios, historial).
 * Se basa en <details>/<summary> (accesible con teclado y lector de pantalla) con una
 * flecha animada y un resumen opcional a la derecha.
 *
 * Props:
 * - title: título de la sección.
 * - icon: icono lucide.
 * - hint: resumen corto a la derecha (p. ej. «3 folios»).
 * - open: abierta al montar.
 */
import { ChevronDown } from 'lucide-vue-next';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        title: string;
        icon?: Component | null;
        hint?: string | null;
        open?: boolean;
    }>(),
    { icon: null, hint: null, open: false },
);
</script>

<template>
    <details
        class="group/disc overflow-hidden rounded-2xl border bg-card shadow-soft-xs"
        :open="open"
    >
        <summary
            class="flex cursor-pointer list-none items-center gap-2.5 px-4 py-3 select-none hover:bg-muted/40 focus-visible:bg-muted/50 focus-visible:outline-none [&::-webkit-details-marker]:hidden"
        >
            <span
                v-if="icon"
                class="grid size-8 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground"
                aria-hidden="true"
            >
                <component :is="icon" class="size-4" />
            </span>
            <span class="min-w-0 flex-1 text-[15px] font-bold">{{
                title
            }}</span>
            <span
                v-if="hint"
                class="shrink-0 truncate text-sm text-muted-foreground"
                >{{ hint }}</span
            >
            <ChevronDown
                class="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-open/disc:rotate-180"
            />
        </summary>
        <div
            class="animate-in border-t px-4 py-3 duration-200 fade-in-0 slide-in-from-top-1 motion-reduce:animate-none"
        >
            <slot />
        </div>
    </details>
</template>
