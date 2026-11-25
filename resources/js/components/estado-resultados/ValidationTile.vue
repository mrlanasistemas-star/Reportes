<script setup lang="ts">
/**
 * ValidationTile — tarjeta resumen de un bloque de la pestaña Validación.
 *
 * La usa ValidationPanel. Muestra icono, título, chip de estado, la cifra principal,
 * una leyenda corta y hasta tres datos secundarios. Toda la tarjeta es un botón
 * «Ver detalle» que abre el modal del bloque.
 *
 * Props:
 * - title, icon, tone: encabezado y color.
 * - status: texto del chip de estado (opcional).
 * - value: cifra principal (ya formateada).
 * - caption: leyenda corta bajo la cifra.
 * - stats: datos secundarios { label, value, tone? }.
 * - action: texto del botón (por omisión «Ver detalle»).
 *
 * Emits:
 * - open: se pidió ver el detalle.
 */
import { ChevronRight } from 'lucide-vue-next';
import type { Component } from 'vue';
import { TONE_ACCENT, TONE_CHIP, TONE_ICON, TONE_TEXT } from './erTone';
import type { ErTone } from './erTone';

withDefaults(
    defineProps<{
        title: string;
        icon: Component;
        tone?: ErTone;
        status?: string | null;
        value?: string | null;
        valueTone?: ErTone;
        caption?: string | null;
        stats?: Array<{ label: string; value: string; tone?: ErTone }>;
        action?: string;
    }>(),
    {
        tone: 'emerald',
        status: null,
        value: null,
        valueTone: 'slate',
        caption: null,
        stats: () => [],
        action: 'Ver detalle',
    },
);
const emit = defineEmits<{ open: [] }>();
</script>

<template>
    <button
        type="button"
        class="group relative flex h-full flex-col overflow-hidden rounded-2xl border bg-card p-4 text-left shadow-soft-xs transition duration-200 hover:-translate-y-0.5 hover:border-foreground/15 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none motion-reduce:transition-none motion-reduce:hover:translate-y-0"
        :aria-label="`${action}: ${title}`"
        @click="emit('open')"
    >
        <span
            class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r opacity-80"
            :class="TONE_ACCENT[tone]"
            aria-hidden="true"
        />
        <span class="flex items-start gap-3">
            <span
                class="grid size-10 shrink-0 place-items-center rounded-xl ring-1 transition group-hover:scale-105"
                :class="TONE_ICON[tone]"
                aria-hidden="true"
            >
                <component :is="icon" class="size-5" />
            </span>
            <span class="min-w-0 flex-1">
                <span
                    class="block text-[15px] leading-snug font-bold text-foreground"
                    >{{ title }}</span
                >
                <span
                    v-if="status"
                    class="mt-1 inline-flex max-w-full items-center truncate rounded-full px-2.5 py-0.5 text-xs font-bold"
                    :class="TONE_CHIP[tone]"
                    >{{ status }}</span
                >
            </span>
        </span>

        <span
            v-if="value"
            class="mt-3 block text-2xl font-extrabold tracking-tight tabular-nums"
            :class="TONE_TEXT[valueTone]"
            >{{ value }}</span
        >
        <span
            v-if="caption"
            class="mt-0.5 line-clamp-2 block text-sm text-muted-foreground"
            >{{ caption }}</span
        >

        <span
            v-if="stats.length"
            class="mt-3 grid gap-1.5 border-t pt-3"
            :class="stats.length >= 3 ? 'grid-cols-3' : 'grid-cols-2'"
        >
            <span v-for="s in stats" :key="s.label" class="min-w-0">
                <span
                    class="block truncate text-xs text-muted-foreground"
                    :title="s.label"
                    >{{ s.label }}</span
                >
                <span
                    class="block truncate text-sm font-bold tabular-nums"
                    :class="s.tone ? TONE_TEXT[s.tone] : ''"
                    :title="s.value"
                    >{{ s.value }}</span
                >
            </span>
        </span>

        <span
            class="mt-auto inline-flex items-center gap-1 pt-3 text-sm font-semibold text-emerald-700 dark:text-emerald-400"
        >
            {{ action }}
            <ChevronRight
                class="size-4 transition group-hover:translate-x-1 motion-reduce:transition-none"
            />
        </span>
    </button>
</template>
