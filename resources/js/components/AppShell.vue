<script setup lang="ts">
/**
 * AppShell — envoltura raíz de los layouts de la aplicación.
 *
 * Lo usan AppSidebarLayout y AppHeaderLayout. En la variante "header" es un
 * contenedor de columna simple; en "sidebar" monta SidebarProvider para dar
 * contexto al menú lateral.
 *
 * Props:
 * - variant: 'sidebar' (por defecto) o 'header'.
 */
import { usePage } from '@inertiajs/vue3';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
};

withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});

/** Estado inicial (abierto/cerrado) del menú lateral compartido por Inertia (prop sidebarOpen). */
const isOpen = usePage().props.sidebarOpen;
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
        <slot />
    </div>
    <SidebarProvider v-else :default-open="isOpen">
        <slot />
    </SidebarProvider>
</template>
