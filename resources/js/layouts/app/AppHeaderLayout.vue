<script setup lang="ts">
/**
 * Variante de layout con navegación superior (header) en lugar de sidebar.
 * Actualmente la app usa AppSidebarLayout; este se conserva como alternativa.
 */
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import type { BreadcrumbItem } from '@/types';

/** Props: migas de pan para el encabezado. */
type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="header">
        <AppHeader :breadcrumbs="breadcrumbs" />
        <AppContent variant="header">
            <slot />
        </AppContent>
    </AppShell>
</template>
