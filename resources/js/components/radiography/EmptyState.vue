<script setup lang="ts">
/**
 * EmptyState — aviso de "sin datos" con icono, título y descripción.
 *
 * Se usa en las pestañas de la radiografía, en ReportesMensuales/Preview y en
 * pasos del Histórico general.
 *
 * Props:
 * - title: mensaje principal.
 * - description: explicación opcional.
 */
import { Inbox } from 'lucide-vue-next';

defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div
        class="rounded-2xl border border-dashed border-border bg-muted/50 p-8 text-center"
    >
        <div
            class="mx-auto flex size-11 items-center justify-center rounded-2xl bg-card text-muted-foreground shadow-sm"
        >
            <Inbox class="size-5" />
        </div>
        <h3 class="mt-3 text-sm font-semibold text-foreground">{{ title }}</h3>
        <p
            v-if="description"
            class="mx-auto mt-1.5 max-w-md text-xs leading-5 text-muted-foreground"
        >
            {{ description }}
        </p>
    </div>
</template>
