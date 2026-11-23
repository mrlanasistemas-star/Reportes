<script setup lang="ts">
/**
 * Layout principal de la aplicación autenticada. Delega en AppSidebarLayout
 * (sidebar + encabezado + navegación inferior móvil). Se asigna
 * automáticamente desde app.ts a toda página que no sea auth/*.
 */
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

/** Props: breadcrumbs = migas de pan que muestra el encabezado (las define cada página). */
const { breadcrumbs = [] } = defineProps<{
    breadcrumbs?: BreadcrumbItem[];
}>();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <slot />
    </AppLayout>
</template>
