<script setup lang="ts">
/**
 * AppContent — contenedor del contenido principal de la aplicación.
 *
 * Lo usan los layouts AppSidebarLayout y AppHeaderLayout. Con la variante
 * "sidebar" envuelve el contenido en SidebarInset (junto al menú lateral);
 * con cualquier otra variante usa un <main> centrado de ancho máximo 7xl.
 *
 * Props:
 * - variant: tipo de layout ('sidebar' por defecto o 'header').
 * - class: clases extra para el contenedor.
 */
import { computed } from 'vue';
import { SidebarInset } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
    class?: string;
};

const props = withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});
/** Clases adicionales recibidas por prop, reenviadas al contenedor. */
const className = computed(() => props.class);
</script>

<template>
    <SidebarInset v-if="props.variant === 'sidebar'" :class="className">
        <slot />
    </SidebarInset>
    <main
        v-else
        class="mx-auto flex h-full w-full max-w-7xl flex-1 flex-col gap-4 rounded-xl"
        :class="className"
    >
        <slot />
    </main>
</template>
