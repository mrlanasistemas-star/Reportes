<script setup lang="ts">
/**
 * AppDataTableShell — envoltura visual para tablas (barra superior + contenido + pie).
 *
 * Componente genérico de la librería interna; actualmente no se importa en
 * ninguna vista.
 *
 * Props:
 * - title: título de la tabla (opcional).
 * - subtitle: texto secundario bajo el título (opcional).
 *
 * Slots: default (la tabla), toolbar (filtros/acciones a la derecha), footer (pie, p. ej. paginación).
 */
defineProps<{
    title?: string;
    subtitle?: string;
}>();
</script>

<template>
    <div class="app-table-wrap">
        <div
            v-if="title || subtitle || $slots.toolbar"
            class="app-table-toolbar"
        >
            <div v-if="title || subtitle">
                <h3
                    v-if="title"
                    class="text-base font-semibold text-foreground"
                >
                    {{ title }}
                </h3>
                <p v-if="subtitle" class="mt-1 text-sm text-muted-foreground">
                    {{ subtitle }}
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <slot name="toolbar" />
            </div>
        </div>

        <div class="app-table-content">
            <slot />
        </div>

        <div v-if="$slots.footer" class="border-t border-border p-4">
            <slot name="footer" />
        </div>
    </div>
</template>
