<script setup lang="ts">
/**
 * LoadingOverlay — capa a pantalla completa con spinner y texto de "procesando".
 *
 * Componente genérico; actualmente no se importa en ninguna vista (la carga
 * entre páginas la resuelve lib/routeLoadingOverlay).
 *
 * Props:
 * - show: muestra u oculta la capa (con transición de opacidad).
 * - text: mensaje junto al spinner (por defecto "Procesando...").
 */
withDefaults(
    defineProps<{
        show: boolean;
        text?: string;
    }>(),
    {
        text: 'Procesando...',
    },
);
</script>

<template>
    <Transition
        enter-active-class="transition duration-200"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition duration-150"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="show"
            class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 backdrop-blur-sm"
        >
            <div
                class="flex items-center gap-3 rounded-3xl border border-border bg-card px-5 py-4 text-card-foreground shadow-2xl"
            >
                <div
                    class="h-5 w-5 animate-spin rounded-full border-2 border-primary border-t-transparent"
                />
                <span class="text-sm font-medium">{{ text }}</span>
            </div>
        </div>
    </Transition>
</template>
