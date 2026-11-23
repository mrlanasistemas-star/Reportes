<script setup lang="ts">
/**
 * AppEmptyState — estado vacío con icono, título y mensaje.
 *
 * Se usa en listados sin resultados (UsersGrid, UserAuditLog, páginas de OKR, etc.).
 *
 * Props:
 * - title: título (por defecto "Sin resultados").
 * - message: explicación (por defecto "No hay información disponible para mostrar.").
 *
 * Slot por defecto: acciones opcionales bajo el mensaje (p. ej. un botón).
 */
import { Inbox } from 'lucide-vue-next';

withDefaults(
    defineProps<{
        title?: string;
        message?: string;
    }>(),
    {
        title: 'Sin resultados',
        message: 'No hay información disponible para mostrar.',
    },
);
</script>

<template>
    <div
        class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border bg-card px-6 py-12 text-center"
    >
        <div class="mb-4 rounded-full bg-muted p-4">
            <Inbox class="h-6 w-6 text-muted-foreground" />
        </div>

        <h3 class="text-base font-semibold text-foreground">
            {{ title }}
        </h3>

        <p class="mt-2 max-w-md text-sm text-muted-foreground">
            {{ message }}
        </p>

        <div class="mt-5">
            <slot />
        </div>
    </div>
</template>
